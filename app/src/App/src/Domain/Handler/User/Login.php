<?php

declare(strict_types=1);

namespace App\Domain\Handler\User;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Domain\Service\UserServiceInterface;
use App\Domain\Service\Exception\UserNotFoundException;
use App\Domain\Documents\User;
use App\Domain\Value\Password;
use App\Domain\Value\Exception\WrongPasswordException;
use Tuupola\Base62;
use Firebase\JWT\JWT;
use Zend\Diactoros\Response\JsonResponse;
use Zend\Expressive\Flash\FlashMessageMiddleware;

use function random_bytes;

final class Login implements MiddlewareInterface
{
    /**
     * @var string
     */
    const LOGGED = 'logged';

    /**
     * @var UsersServiceInterface
     */
    private $usersService;

    /**
     * @var string
     */
    private $jwtSecret;

    /**
     * @var array
     */
    private $jwtSession;

    public function __construct(
        UserServiceInterface $usersService,
        string $jwtSecret,
        array $jwtSession
    )
    {
        $this->usersService = $usersService;
        $this->jwtSecret = $jwtSecret;
        $this->jwtSession = $jwtSession;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
    {
        $data = $request->getParsedBody();
        
        $br = $request->getServerParams()['HTTP_USER_AGENT'];
        $ip = $request->getAttribute('_ip');

        try {
            $user = $this->usersService->getByEmail($data['email'], User::class);
        } catch (UserNotFoundException $e) {
            return new JsonResponse([
                'code' => '401',
                'message' => $e->getMessage()
            ], 401);
        }

        try {
           Password::verify($data['password'], $user->getPassword());
        } catch (WrongPasswordException $e) {
            $this->usersService->createLog($user, $br, $ip, false);
            return new JsonResponse([
                'code' => '401',
                'message' => $e->getMessage()
            ], 401);
        }

        // log success
        $this->usersService->createLog($user, $br, $ip, true);

        $future = new \DateTime('+20 minutes');

        $payload = [
            'iat' => (new \DateTime())->getTimestamp(),
            'exp' => $future->getTimestamp(),
            'jti' => (new Base62)->encode(random_bytes(16)),
            'data' => [
                'id' => (string) $user->getId()->__toString(),
                'name' => $user->getName()->__toString(),
                'email' => $user->getEmail()->__toString()
            ]
        ];

        $token = JWT::encode($payload, $this->jwtSecret, 'HS256');
        $session = $request->getAttribute('session');

        $session->set($this->jwtSession['session_name'], $token);
        $session->set($this->jwtSession['session_exp'], $future);

        $flashMessages = $request->getAttribute(FlashMessageMiddleware::FLASH_ATTRIBUTE);
        $flashMessages->flash(self::LOGGED, [
            'logged' => true,
            'message' => 'Login successfully, you are on your profile!'
        ]);

        return new JsonResponse([
            'token' => $token,
            'expires' => $future->getTimestamp()
        ]);
    }
}
