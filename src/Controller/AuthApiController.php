<?php

namespace App\Controller;

use App\Repository\UserRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\User;
use App\Entity\Device;
use App\Repository\DeviceRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AuthApiController extends AbstractController
{
    #[Route('/api/login', name: 'login_api', methods: ['POST'])]
    public function login(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, UserRepository $userRepository, JWTTokenManagerInterface $jwtManager): Response
    {
        $data = json_decode($request->getContent(), true);

        $user = $userRepository->findOneBy(['email' => $data['email']]);

        if (!$passwordHasher->isPasswordValid($user, $data['password'])); {
            return $this->json([], Response::HTTP_NOT_FOUND);
        }

        $devices = $user->getDevices();

        if (count($devices) >= 5) {
            $oldest = $devices->first();
            $em->remove($oldest);
        }

        $refreshToken = bin2hex(random_bytes(64));

        $device = new Device();
        $device->setUser($user);
        $device->setUserAgent($request->headers->get('User-Agent'));
        $device->setIp($request->getClientIp());
        $device->setRefreshToken(password_hash($refreshToken, PASSWORD_BCRYPT));
        $device->setExpiresAt((new DateTime())->modify('+7 days'));
        $device->setLastUsedAt((new DateTime()));
        $device->setIsRevoked(false);
        $device->setIsCompromised(false);

        $em->persist($device);
        $em->flush();

        $accessToken = $jwtManager->create($user);

        return $this->json([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'device_id' => $device->getId()
        ], Response::HTTP_OK);
    }
    #[Route('/api/token/refresh', name: 'refresh_token', methods: ['POST'])]
    public function refresh(Request $request, EntityManagerInterface $em, DeviceRepository $deviceRepository, JWTTokenManagerInterface $jwtManager): Response
    {
        $data = json_decode($request->getContent(), true);
        $refreshToken = $data['refresh_token'];

        $allDevices = $deviceRepository->findAll();

        $device = null;
        foreach ($allDevices as $de) {
            if (password_verify($refreshToken, $de->getRefreshToken())) {
                $device = $de;
                break;
            }
        }

        if ($device->isCompromised()) {
            $user = $device->getUser();
            $userDevices = $deviceRepository->findBy(['user' => $user]);

            foreach ($userDevices as $userDevice) {
                $userDevice->setRefreshToken('');
                $userDevice->setIsRevoked(true);
                $userDevice->setIsCompromised(true);
                $userDevice->setExpiresAt(new DateTime('-1 day'));
            }

            $em->flush();

            return $this->json([], Response::HTTP_UNAUTHORIZED);
        }

        if ($device->isRevoked() || $device->getExpiresAt() < new DateTime()) {
            return $this->json([], Response::HTTP_NOT_FOUND);
        }

        if (
            $device->getPreviouseRefreshToken() !== null &&
            password_verify($refreshToken, $device->getPreviouseRefreshToken())
        ) {

            $device->setIsCompromised(true);

            $user = $device->getUser();
            $userDevices = $deviceRepository->findBy(['user' => $user]);

            foreach ($userDevices as $userDevice) {
                $userDevice->setRefreshToken('');
                $userDevice->setIsRevoked(true);
                $userDevice->setIsCompromised(true);
                $userDevice->setExpiresAt(new DateTime('-1 day'));
            }

            $em->flush();

            return $this->json([], Response::HTTP_UNAUTHORIZED);
        }

        $newRefreshToken = bin2hex(random_bytes(64));
        $newHash = password_hash($newRefreshToken, PASSWORD_BCRYPT);

        $device->setRefreshToken($newHash);
        $device->setLastUsedAt($refreshToken);
        $device->setExpiresAt((new DateTime())->modify('+7 days'));
        $device->setLastUsedAt((new DateTime()));

        $em->flush();

        $accessToken = $jwtManager->create($this->getUser());

        return $this->json([
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken,
            'device_id' => $device->getId()
        ], Response::HTTP_OK);
    }
    #[Route('/api/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request, EntityManagerInterface $em, DeviceRepository $deviceRepository): Response
    {
        $data = json_decode($request->getContent(), true);
        $refreshToken = $data['refresh_token'];

        if ($refreshToken) {
            $allDevices = $deviceRepository->findAll();

            foreach ($allDevices as $device) {
                if (password_verify($refreshToken, $device->getRefreshToken())) {
                    $device->setIsRevoked(true);
                    $device->setRefreshToken('');
                    $em->flush();
                    break;
                }
            }
        }
        return $this->json([], Response::HTTP_OK);
    }
    #[Route('/api/devices', name: 'devices', methods: ['GET'])]
    public function devices(DeviceRepository $deviceRepository): Response
    {
        $content = $deviceRepository->createQueryBuilder('d')
            ->select('d.ip, d.userAgent, d.lastUsedAt')
            ->where('d.user=:user')
            ->setParameter(':user', $this->getUser())
            ->getQuery()
            ->getResult();
        return $this->json($content, Response::HTTP_OK);
    }
}
