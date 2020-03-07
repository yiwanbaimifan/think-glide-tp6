<?php

/*
 * This file is part of the slince/think-glide
 *
 * (c) Slince <taosikai@yeah.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Slince\Glide;

use League\Glide\Server;
use League\Glide\ServerFactory;
use League\Glide\Signatures\SignatureFactory;
use League\Glide\Urls\UrlBuilderFactory;
use Symfony\Component\OptionsResolver\OptionsResolver;
use think\Container;
use think\facade\App;
use think\Request;
use think\Response;

class GlideMiddleware
{
    /**
     * @var array
     */
    protected $options;

    /**
     * @var array
     */
    protected $query;

    public function __construct(array $options = [])
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'baseUrl' => '/images',
            'cache' => App::getRuntimePath().'/glide',
            'cacheTime' => '+1 day',
            'signKey' => false,
            'glide' => [],
            'onException' => function(\Exception $exception, Request $request, Server $server){
                throw $exception;
            },
        ]);
        $resolver->setRequired('source');

        $this->options = $resolver->resolve($options);
        
        //如果启动安全校验，需要注入服务
        if ($this->options['signKey']) {
            $urlBuilder = UrlBuilderFactory::create($this->options['baseUrl'], $this->options['signKey']);
            Container::set('glide.url_builder', $urlBuilder);
        }
    }

    public function __invoke(Request $request, $next)
    {
        // $request->path = $request->pathinfo();
        // 修改原来的错误代码
        //$uri = urldecode( $request->app . '/' . $request->path);
        // 源代码
        // $uri = urldecode( $request->app() . '/' . $request->path());
        $uri = urldecode($request->pathinfo());
                
        parse_str($request->query(), $this->query);
        
        // dump($uri);
        if (!preg_match("#^{$this->options['baseUrl']}#", '/'.$uri, $matches)) {
            return $next($request);
        }
        
        // dump($this->query);
        // dump($this->options['baseUrl']);
        // dump($next);
        $server = $this->createGlideServer();
        try {
            //检查安全签名
            $this->checkSignature($uri);
            $response = $this->handleRequest($server, $request);
        } catch (\Exception $exception) {
            $response = call_user_func($this->options['onException'], $exception, $request, $server);
        }

        return $response;
    }

    /**
     * @param Server  $server
     * @param Request $request
     *
     * @return Response
     *
     * @throws \League\Flysystem\FileNotFoundException
     * @throws \League\Glide\Filesystem\FileNotFoundException
     */
    protected function handleRequest(Server $server, Request $request)
    {
        //检查是否重新更新了
        $modifiedTime = null;
        if ($this->options['cacheTime']) {
            $modifiedTime = $server->getSource()
                ->getTimestamp($server->getSourcePath($request->pathinfo()));

            $response = $this->applyModified($modifiedTime, $request);
            if (false !== $response) {
                return $response;
            }
        }

        //如果已经更新了重新从缓存拉取图像
        if (null === $server->getResponseFactory()) {
            $server->setResponseFactory(new ResponseFactory());
        }
        $response = $server->getImageResponse($request->pathinfo(), $this->query);

        return $this->applyCacheHeaders($response, $modifiedTime);
    }

    protected function applyCacheHeaders(Response $response, $modifiedTime)
    {
        $expire = strtotime($this->options['cacheTime']);
        $maxAge = $expire - time();

        return $response
            ->header([
                'Cache-Control' => 'public,max-age='.$maxAge,
                'Date' => gmdate('D, j M Y G:i:s \G\M\T', time()),
                'Last-Modified' => gmdate('D, j M Y G:i:s \G\M\T', (int) $modifiedTime),
                'Expires' => gmdate('D, j M Y G:i:s \G\M\T', $expire)
            ]);
    }

    /**
     * @param int     $modifiedTime
     * @param Request $request
     *
     * @return false|Response
     */
    protected function applyModified($modifiedTime, Request $request)
    {
        //如果没有修改直接返回
        if ($this->isNotModified($request, $modifiedTime)) {
            $response = new Response('', 304);

            return $this->applyCacheHeaders($response, $modifiedTime);
        }

        return false;
    }

    /**
     * @param Request $request
     * @param $modifiedTime
     *
     * @return bool
     */
    protected function isNotModified(Request $request, $modifiedTime)
    {
        $modifiedSince = $request->header('If-Modified-Since');

        if (!$modifiedSince) {
            return false;
        }

        return strtotime($modifiedSince) === (int) $modifiedTime;
    }

    /**
     * @param string $uri
     *
     * @throws \League\Glide\Signatures\SignatureException
     */
    protected function checkSignature($uri)
    {
        if (!$this->options['signKey']) {
            return;
        }
        SignatureFactory::create($this->options['signKey'])->validateRequest(
            $uri,
            $this->query
        );
    }

    /**
     * @return \League\Glide\Server
     */
    protected function createGlideServer()
    {
        return ServerFactory::create(array_merge([
            'source' => $this->options['source'],
            'cache' => $this->options['cache'],
            'base_url' => $this->options['baseUrl'],
        ], $this->options['glide']));
    }

    /**
     * 创建 middleware 闭包.
     *
     * @param array $options
     *
     * @return \Closure
     */
    public static function factory($options)
    {
        $middleware = new self($options);
        
        return function(Request $request, $next) use ($middleware){
            return $middleware($request, $next);
        };
    }
}
