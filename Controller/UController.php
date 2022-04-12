<?php /** @noinspection PhpMultipleClassDeclarationsInspection */

namespace Newageerp\SfUservice\Controller;

use DateTime;
use Doctrine\Persistence\ObjectRepository;
use Exception;
use Newageerp\SfSocket\Event\SocketSendPoolEvent;
use Newageerp\SfUservice\Service\UService;
use Newageerp\SfSerializer\Serializer\ObjectSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Newageerp\SfAuth\Service\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Newageerp\SfCrud\Interface\IOnSaveService;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;

/**
 * @Route(path="/app/nae-core/u")
 */
class UController extends UControllerBase
{
    /**
     * @Route ("/ping", methods={"GET"})
     * @return JsonResponse
     * @OA\Get (operationId="NAEPing")
     * @OA\Response(
     *     response="200",
     *     description="NAEPing",
     *     @OA\JsonContent(
     *        type="object",
     *     )
     * )
     */
    public function ping(): JsonResponse
    {
        return $this->json(['success' => 1, 'data' => [['success' => 1]]]);
    }

    /**
     * @Route(path="/getMultiple", methods={"POST"})
     * @OA\Post (operationId="NAEUMultipleList")
     * @throws Exception
     */
    public function getMultiple(
        Request  $request,
        UService $uService
    ): JsonResponse
    {
        $request = $this->transformJsonBody($request);

        $user = $this->findUser($request);
        if (!$user) {
            throw new Exception('Invalid user');
        }
        AuthService::getInstance()->setUser($user);

        $requestData = $request->get('data');

        $output = [];
        foreach ($requestData as $data) {
            $output[$data['schema']] =
                $uService->getListDataForSchema(
                    $data['schema'],
                    $data['page'] ?? 1,
                    $data['pageSize'] ?? 20,
                    $data['fieldsToReturn'] ?? ['id'],
                    $data['filters'] ?? [],
                    $data['extraData'] ?? [],
                    $data['sort'] ?? [],
                    $data['totals'] ?? []
                );
        }
        return $this->json($output);
    }

    /**
     * @Route(path="/get/{schema}", methods={"POST"})
     * @OA\Post (operationId="NAEUList")
     */
    public function getList(
        Request  $request,
        UService $uService,
    ): Response
    {
        try {
            $request = $this->transformJsonBody($request);

            $user = $this->findUser($request);
            if (!$user) {
                throw new Exception('Invalid user');
            }
            AuthService::getInstance()->setUser($user);

            $schema = $request->get('schema');
            $page = $request->get('page') ? $request->get('page') : 1;
            $pageSize = $request->get('pageSize') ? $request->get('pageSize') : 20;
            $fieldsToReturn = $request->get('fieldsToReturn') ? $request->get('fieldsToReturn') : ['id'];
            $filters = $request->get('filters') ? $request->get('filters') : [];
            $extraData = $request->get('extraData') ? $request->get('extraData') : [];
            $sort = $request->get('sort') ? $request->get('sort') : [];
            $totals = $request->get('totals') ? $request->get('totals') : [];

            return $this->json($uService->getListDataForSchema($schema, $page, $pageSize, $fieldsToReturn, $filters, $extraData, $sort, $totals));
        } catch (Exception $e) {
            $response = $this->json([
                'description' => $e->getMessage(),
                'f' => $e->getFile(),
                'l' => $e->getLine()

            ]);
            $response->setStatusCode(Response::HTTP_BAD_REQUEST);
            return $response;
        }
    }

    /**
     * @Route ("/save/{schema}", methods={"POST"})
     * @OA\Post (operationId="NAEUSave")
     */
    public function USave(Request $request, EntityManagerInterface $entityManager, IOnSaveService $onSaveService): JsonResponse
    {
        try {
            $request = $this->transformJsonBody($request);

            if (!($user = $this->findUser($request))) {
                throw new Exception('Invalid user');
            }
            AuthService::getInstance()->setUser($user);

            $id = $request->get('id');
            $data = $request->get('data');
            $fieldsToReturn = $request->get('fieldsToReturn');

            $schema = $request->get('schema');
            $className = $this->convertSchemaToEntity($schema);
            /**
             * @var ObjectRepository $repository
             */
            $repository = $entityManager->getRepository($className);

            $properties = $this->getPropertiesForSchema($schema);

            if ($id === 'new') {
                $element = new $className();

                if (method_exists($element, 'setCreator')) {
                    $element->setCreator($user);
                }
            } else {
                $element = $repository->find($id);
            }

            $skipped = [];
            $notString = [];
            foreach ($data as $key => $val) {
                if ($key === 'createdAt' || $key === 'updatedAt') {
                    continue;
                }
                $type = null;
                $format = null;
                $as = null;
                if (isset($properties[$key], $properties[$key]['type']) && $properties[$key]['type']) {
                    $type = $properties[$key]['type'];
                }
                if (isset($properties[$key], $properties[$key]['format']) && $properties[$key]['format']) {
                    $format = $properties[$key]['format'];
                }
                if (isset($properties[$key], $properties[$key]['as']) && $properties[$key]['as']) {
                    $as = $properties[$key]['as'];
                }

                if ($type === 'string') {
                    if ($format === 'date' || $format === 'datetime' || $format === 'date-time') {
                        $val = $val ? new DateTime($val) : null;
                    } else {
                        if (is_string($val)) {
                            $val = trim($val);
                        } else {
                            $notString[] = $key;
                        }
                    }
                }

                if ($type === 'rel') {
                    if (is_array($val) && isset($val['id'])) {
                        $typeClassName = $this->convertSchemaToEntity($format);
                        $repository = $entityManager->getRepository($typeClassName);
                        $val = $repository->find($val['id']);
                    } else {
                        $val = null;
                    }


                    $mapped = null;
                    if (isset($properties[$key]['additionalProperties'])) {
                        foreach ($properties[$key]['additionalProperties'] as $prop) {
                            if (isset($prop['mapped'])) {
                                $mapped = $prop['mapped'];
                            }
                        }
                    }
                    if ($mapped) {
                        $mapGetter = 'get' . lcfirst($key);
                        $mapSetter = 'set' . lcfirst($mapped);

                        $mapEl = $element->$mapGetter();
                        if ($mapEl) {
                            $mapEl->$mapSetter(null);
                        }
                        if ($val) {
                            $val->$mapSetter($element);
                        }
                    }
                }

                if ($type === 'array' && $format !== 'string') {
                    $mapped = null;
                    if (isset($properties[$key]['additionalProperties'])) {
                        foreach ($properties[$key]['additionalProperties'] as $prop) {
                            if (isset($prop['mapped'])) {
                                $mapped = $prop['mapped'];
                            }
                        }
                    }
                    if ($mapped) {
                        $mainGetter = 'get' . lcfirst($key);

                        $relClassName = $this->convertSchemaToEntity($format);
                        /**
                         * @var ObjectRepository $repository
                         */
                        $relRepository = $entityManager->getRepository($relClassName);

                        $relElements = $relRepository->findBy([$mapped => $element]);
                        $setter = 'set' . lcfirst($mapped);

                        $element->{$mainGetter}()->clear();

                        foreach ($relElements as $relElement) {
                            $relElement->$setter(null);
                            $entityManager->persist($relElement);

                            if ($element->{$mainGetter}()->contains($relElement)) {
                                $element->{$mainGetter}()->removeElement($relElement);
                            }
                        }

                        foreach ($val as $relVal) {
                            $relElement = $relRepository->find($relVal['id']);
                            if ($relElement) {
                                $relElement->$setter($element);
                                $entityManager->persist($relElement);

                                $element->$mainGetter()->add($relElement);
                            }
                        }
                    } else {
                        $method = 'set' . lcfirst($key);
                        if (method_exists($element, $method)) {
                            $element->$method($val);
                        } else {
                            $skipped[] = $method;
                        }
                    }
                } else {
                    $method = 'set' . lcfirst($key);
                    if (method_exists($element, $method)) {
                        if ($type === 'number' && $format === 'float') {
                            $element->$method((float)$val);
                        } else if ($type === 'int' || $type === 'integer' || ($type === 'number' && $format === 'integer') || ($type === 'number' && $format === 'int')) {
                            $element->$method((float)$val);
                        } else {
                            $element->$method($val);
                        }
                    } else {
                        $skipped[] = $method;
                    }
                }

                if ($type === 'array' && mb_strpos($as, 'entity:') === 0) {
                    $method = 'set' . lcfirst($key) . 'Value';

                    if (method_exists($element, $method)) {
                        $asArray = explode(":", $as);
                        $className = 'App\Entity\\' . ucfirst($asArray[1]);
                        $repo = $this->em->getRepository($className);
                        $methodGet = 'get' . ucfirst($asArray[2]);
                        if ($val) {
                            $cache = [];
                            foreach ($val as $valId) {
                                $valObject = $repo->find($valId);
                                if ($valObject) {
                                    $cache[] = $valObject->$methodGet();
                                }
                                $element->$method(implode(", ", $cache));
                            }
                        } else {
                            $element->$method('');
                        }
                    }
                }
            }

            $onSaveService->onSave($element);

            $requiredError = [];
            if (!isset($data['skipRequiredCheck'])) {
                $requiredFields = [];
                foreach ($this->getSchemas() as $schemaEl) {
                    if ($schemaEl['schema'] === $schema) {
                        if (isset($schemaEl['required'])) {
                            $requiredFields = $schemaEl['required'];
                        }
                    }
                }

                foreach ($requiredFields as $requiredField) {
                    $method = 'get' . lcfirst($requiredField);
                    if (method_exists($element, $method)) {
                        if (!$element->$method()) {
                            $requiredError[] = $requiredField;
                        }
                    }
                }
            }
            if (count($requiredError) > 0) {
                $response = $this->json(['error' => 1, 'description' => 'Užpildykite būtinus laukus', 'fields' => $requiredError]);
                $response->setStatusCode(Response::HTTP_BAD_REQUEST);
                return $response;
            }

            $entityManager->persist($element);
            $entityManager->flush();

            $event = new SocketSendPoolEvent();
            $this->eventDispatcher->dispatch($event, SocketSendPoolEvent::NAME);

            $jsonContent = ObjectSerializer::serializeRow($element, $fieldsToReturn);

            return $this->json(
                [
                    'skipped' => $skipped,
                    'element' => $jsonContent
                ]
            );
        } catch (Exception $e) {
            $response = $this->json([
                'description' => $e->getMessage(),
                'f' => $e->getFile(),
                'l' => $e->getLine()

            ]);
            $response->setStatusCode(Response::HTTP_BAD_REQUEST);
            return $response;
        }
    }

    /**
     * @Route ("/remove/{schema}", methods={"POST"})
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return JsonResponse
     * @OA\Post (operationId="NAEURemove")
     */
    public function URemove(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        try {
            $request = $this->transformJsonBody($request);

            if (!($user = $this->findUser($request))) {
                throw new Exception('Invalid user');
            }
            AuthService::getInstance()->setUser($user);

            $id = $request->get('id');
            $schema = $request->get('schema');
            $className = $this->convertSchemaToEntity($schema);
            /**
             * @var ObjectRepository $repository
             */
            $repository = $entityManager->getRepository($className);

            $element = $repository->find($id);

            if ($element) {
                $entityManager->remove($element);
            }

            $entityManager->flush();

            $event = new SocketSendPoolEvent();
            $this->eventDispatcher->dispatch($event, SocketSendPoolEvent::NAME);

            return $this->json(
                [
                    'success' => 1,
                ]
            );
        } catch (Exception $e) {
            $response = $this->json([
                'description' => $e->getMessage(),
                'f' => $e->getFile(),
                'l' => $e->getLine()

            ]);
            $response->setStatusCode(Response::HTTP_BAD_REQUEST);
            return $response;
        }
    }

}
