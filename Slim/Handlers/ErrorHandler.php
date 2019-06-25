<?php
/**
 * Slim Framework (https://slimframework.com)
 *
 * @license https://github.com/slimphp/Slim/blob/4.x/LICENSE.md (MIT License)
 */

declare(strict_types=1);

namespace Slim\Handlers;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Error\Renderers\HtmlErrorRenderer;
use Slim\Error\Renderers\JsonErrorRenderer;
use Slim\Error\Renderers\PlainTextErrorRenderer;
use Slim\Error\Renderers\XmlErrorRenderer;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Interfaces\CallableResolverInterface;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

/**
 * Default Slim application error handler
 *
 * It outputs the error message and diagnostic information in one of the following formats:
 * JSON, XML, Plain Text or HTML based on the Accept header.
 */
class ErrorHandler implements ErrorHandlerInterface
{
    /**
     * @var ErrorRendererInterface|string|callable
     */
    protected $defaultErrorRenderer = HtmlErrorRenderer::class;

    /**
     * @var array
     */
    protected $errorRenderers = [
        'application/json' => JsonErrorRenderer::class,
        'application/xml' => XmlErrorRenderer::class,
        'text/xml' => XmlErrorRenderer::class,
        'text/html' => HtmlErrorRenderer::class,
        'text/plain' => PlainTextErrorRenderer::class,
    ];

    /**
     * @var bool
     */
    protected $displayErrorDetails;

    /**
     * @var bool
     */
    protected $logErrors;

    /**
     * @var bool
     */
    protected $logErrorDetails;

    /**
     * @var string
     */
    protected $contentType;

    /**
     * @var string
     */
    protected $method;

    /**
     * @var ServerRequestInterface
     */
    protected $request;

    /**
     * @var Throwable
     */
    protected $exception;

    /**
     * @var ErrorRendererInterface|callable|null
     */
    protected $renderer;

    /**
     * @var int
     */
    protected $statusCode;

    /**
     * @var CallableResolverInterface
     */
    protected $callableResolver;

    /**
     * @var ResponseFactoryInterface
     */
    protected $responseFactory;

    /**
     * @param CallableResolverInterface $callableResolver
     * @param ResponseFactoryInterface  $responseFactory
     */
    public function __construct(CallableResolverInterface $callableResolver, ResponseFactoryInterface $responseFactory)
    {
        $this->callableResolver = $callableResolver;
        $this->responseFactory = $responseFactory;
    }

    /**
     * Invoke error handler
     *
     * @param ServerRequestInterface $request             The most recent Request object
     * @param Throwable              $exception           The caught Exception object
     * @param bool                   $displayErrorDetails Whether or not to display the error details
     * @param bool                   $logErrors           Whether or not to log errors
     * @param bool                   $logErrorDetails     Whether or not to log error details
     *
     * @return ResponseInterface
     */
    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        $this->displayErrorDetails = $displayErrorDetails;
        $this->logErrors = $logErrors;
        $this->logErrorDetails = $logErrorDetails;
        $this->request = $request;
        $this->exception = $exception;
        $this->method = $request->getMethod();
        $this->statusCode = $this->determineStatusCode();
        $this->contentType = $this->determineContentType($request);
        $this->renderer = $this->determineRenderer();

        if ($logErrors) {
            $this->writeToErrorLog();
        }

        return $this->respond();
    }

    /**
     * @return int
     */
    protected function determineStatusCode(): int
    {
        if ($this->method === 'OPTIONS') {
            return 200;
        }

        if ($this->exception instanceof HttpException) {
            return $this->exception->getCode();
        }

        return 500;
    }

    /**
     * Determine which content type we know about is wanted using Accept header
     *
     * Note: This method is a bare-bones implementation designed specifically for
     * Slim's error handling requirements. Consider a fully-feature solution such
     * as willdurand/negotiation for any other situation.
     *
     * @param ServerRequestInterface $request
     * @return string
     */
    protected function determineContentType(ServerRequestInterface $request): string
    {
        $acceptHeader = $request->getHeaderLine('Accept');
        $selectedContentTypes = array_intersect(
            explode(',', $acceptHeader),
            array_keys($this->errorRenderers)
        );
        $count = count($selectedContentTypes);

        if ($count) {
            $current = current($selectedContentTypes);

            /**
             * Ensure other supported content types take precedence over text/plain
             * when multiple content types are provided via Accept header.
             */
            if ($current === 'text/plain' && $count > 1) {
                return next($selectedContentTypes);
            }

            return $current;
        }

        if (preg_match('/\+(json|xml)/', $acceptHeader, $matches)) {
            $mediaType = 'application/' . $matches[1];
            if (array_key_exists($mediaType, $this->errorRenderers)) {
                return $mediaType;
            }
        }

        return 'text/html';
    }

    /**
     * Determine which renderer to use based on content type
     * Overloaded $renderer from calling class takes precedence over all
     *
     * @return callable
     *
     * @throws RuntimeException
     */
    protected function determineRenderer(): callable
    {
        $renderer = $this->renderer;

        if ($renderer !== null
            && (
                (is_string($renderer) && !class_exists($renderer))
                || !in_array(ErrorRendererInterface::class, class_implements($renderer), true)
            )
        ) {
            throw new RuntimeException(
                'Non compliant error renderer provided (%s). ' .
                'Renderer must implement the ErrorRendererInterface'
            );
        }

        if ($renderer === null) {
            if (array_key_exists($this->contentType, $this->errorRenderers)) {
                $renderer = $this->errorRenderers[$this->contentType];
            } else {
                $renderer = $this->defaultErrorRenderer;
            }
        }

        return $this->callableResolver->resolve($renderer);
    }

    /**
     * Register an error renderer for a specific content-type
     *
     * @param string  $contentType  The content-type this renderer should be registered to
     * @param ErrorRendererInterface|string|callable $errorRenderer The error renderer
     */
    public function registerErrorRenderer(string $contentType, $errorRenderer): void
    {
        $this->errorRenderers[$contentType] = $errorRenderer;
    }

    /**
     * Set the default error renderer
     *
     * @param ErrorRendererInterface|string|callable $errorRenderer The default error renderer
     */
    public function setDefaultErrorRenderer($errorRenderer): void
    {
        $this->defaultErrorRenderer = $errorRenderer;
    }

    /**
     * Write to the error log if $logErrors has been set to true
     *
     * @return void
     */
    protected function writeToErrorLog(): void
    {
        $renderer = new PlainTextErrorRenderer();
        $error = $renderer->render($this->exception, $this->logErrorDetails);
        $error .= "\nView in rendered output by enabling the \"displayErrorDetails\" setting.\n";
        $this->logError($error);
    }

    /**
     * Wraps the error_log function so that this can be easily tested
     *
     * @param string $error
     * @return void
     */
    protected function logError(string $error): void
    {
        error_log($error);
    }

    /**
     * @return ResponseInterface
     */
    protected function respond(): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($this->statusCode);
        $response = $response->withHeader('Content-type', $this->contentType);

        if ($this->exception instanceof HttpMethodNotAllowedException) {
            $allowedMethods = implode(', ', $this->exception->getAllowedMethods());
            $response = $response->withHeader('Allow', $allowedMethods);
        }

        if (is_callable($this->renderer)) {
            $body = call_user_func($this->renderer, $this->exception, $this->displayErrorDetails);
            $response->getBody()->write($body);
        }

        return $response;
    }
}
