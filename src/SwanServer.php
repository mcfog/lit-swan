<?php namespace Lit\Swan;

use Psr\Http\Message\ResponseInterface;
use Zend\Diactoros\Request;
use Zend\Diactoros\Response;
use Zend\Diactoros\ServerRequest;
use Zend\Diactoros\ServerRequestFactory;
use Zend\Diactoros\Stream;

class SwanServer extends \swoole_http_server
{
    const CHUNK_SIZE = 1048576;//1M
    /**
     * @var callable
     */
    protected $middleware;

    public function __construct(
        callable $psrMiddleware,
        $host,
        $port,
        $mode = SWOOLE_PROCESS,
        $sock_type = SWOOLE_SOCK_TCP
    ) {
        parent::__construct($host, $port, $mode, $sock_type);

        $this->on('request', [$this, 'onRequest']);
        $this->middleware = $psrMiddleware;
    }

    public function onRequest(\swoole_http_request $req, \swoole_http_response $res)
    {
        $psrReq = static::makePsrRequest($req);

        $psrRes = new Response();

        /**
         * @var Response $psrRes
         */
        $psrRes = call_user_func($this->middleware, $psrReq, $psrRes, function ($req, $res) {
            return $res;
        });

        static::emitResponse($res, $psrRes);
    }


    public static function emitResponse(\swoole_http_response $res, ResponseInterface $psrRes)
    {
        $res->status($psrRes->getStatusCode());
        foreach ($psrRes->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $res->header($name, $value);
            }
        }

        $body = $psrRes->getBody();
        $body->rewind();
        if ($body->getSize() > static::CHUNK_SIZE) {
            while (!$body->eof()) {
                $res->write($body->read(static::CHUNK_SIZE));
            }
            $res->end();
        } else {
            $res->end($body->getContents());
        }
    }

    /**
     * @param \swoole_http_request $req
     * @return \Psr\Http\Message\ServerRequestInterface
     */
    public static function makePsrRequest(\swoole_http_request $req)
    {
        $server = [];
        foreach ($req->server as $key => $value) {
            $server[strtoupper($key)] = $value;
        }
        $server = ServerRequestFactory::normalizeServer($server);

        $files = isset($req->files)
            ? ServerRequestFactory::normalizeFiles($req->files)
            : [];
        $cookies = isset($req->cookie) ? $req->cookie : [];
        $query = isset($req->get) ? $req->get : [];
        $body = isset($req->post) ? $req->post : [];

        $stream = new Stream('php://memory', 'wb+');
        $stream->write($req->rawContent());
        $stream->rewind();

        $headers = ServerRequestFactory::marshalHeaders($server);
        $request = new ServerRequest(
            $server,
            $files,
            ServerRequestFactory::marshalUriFromServer($server, $headers),
            ServerRequestFactory::get('REQUEST_METHOD', $server, 'GET'),
            $stream,
            $headers
        );

        return $request
            ->withCookieParams($cookies)
            ->withQueryParams($query)
            ->withParsedBody($body);
    }
}
