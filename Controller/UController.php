<?php /** @noinspection PhpMultipleClassDeclarationsInspection */

namespace Newageerp\SfUservice\Controller;

use DateTime;
use Doctrine\Persistence\ObjectRepository;
use Exception;

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
use Newageerp\SfUservice\Events\UOnSaveCustomEvent;

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
     * @Route(path="/getMultipleForModel", methods={"POST"})
     * @OA\Post (operationId="NAEUMultipleListForModels")
     * @throws Exception
     */
    public function getMultipleForModel(
        Request  $request,
        UService $uService
    ): JsonResponse
    {
        $modelFields = json_decode(file_get_contents('/var/www/symfony/assets/model-fields.json'), true);

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
                    isset($modelFields[$data['schema']]) ?$modelFields[$data['schema']]: ['id'],
                    $data['filters'] ?? [],
                    $data['extraData'] ?? [],
                    $data['sort'] ?? [],
                    $data['totals'] ?? []
                );
        }
        return $this->json($output);
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
    public function USave(Request $request, EntityManagerInterface $entityManager, UService $uService): JsonResponse
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

            if ($id === 'new') {
                $element = new $className();

                if (method_exists($element, 'setCreator')) {
                    $element->setCreator($user);
                }
            } else {
                $element = $repository->find($id);
            }

            $uService->updateElement($element, $data, $schema);

            $entityManager->flush();

            $this->sendSocketPool();

            $jsonContent = ObjectSerializer::serializeRow($element, $fieldsToReturn);

            if (isset($data['_events'])) {
                foreach ($data['_events'] as $eventName) {
                    $ev = new UOnSaveCustomEvent($element, $data, $schema);
                    $this->getEventDispatcher()->dispatch($ev, $eventName);
                }
            }

            return $this->json(['element' => $jsonContent]);
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
     * @Route ("/saveMultiple", methods={"POST"})
     * @OA\Post (operationId="NAEUSaveMultiple")
     */
    public function USaveMultiple(Request $request, EntityManagerInterface $entityManager, UService $uService): JsonResponse
    {
        try {
            $request = $this->transformJsonBody($request);

            if (!($user = $this->findUser($request))) {
                throw new Exception('Invalid user');
            }
            AuthService::getInstance()->setUser($user);

            $data = $request->get('data');
            $fieldsToReturn = $request->get('fieldsToReturn');

            $skipSocketMessages = $request->get('skipSocketMessages');

            $schema = $request->get('schema');
            $className = $this->convertSchemaToEntity($schema);
            /**
             * @var ObjectRepository $repository
             */
            $repository = $entityManager->getRepository($className);

            $return = [];
            foreach ($data as $item) {
                if ($item['id'] === 'new') {
                    $element = new $className();

                    if (method_exists($element, 'setCreator')) {
                        $element->setCreator($user);
                    }
                } else {
                    $element = $repository->find($item['id']);
                }

                $uService->updateElement($element, $item['data'], $schema);

//                $return[] = ObjectSerializer::serializeRow($element, $fieldsToReturn);
            }

            $entityManager->flush();

            if (!$skipSocketMessages) {
                $this->sendSocketPool();
            }

            return $this->json(['elements' => $return]);
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

            $this->sendSocketPool();

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


    /**
     * @Route ("/removeMultiple", methods={"POST"})
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return JsonResponse
     * @OA\Post (operationId="NAEURemoveMultiple")
     */
    public function URemoveMultiple(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        try {
            $request = $this->transformJsonBody($request);

            if (!($user = $this->findUser($request))) {
                throw new Exception('Invalid user');
            }
            AuthService::getInstance()->setUser($user);

            $ids = $request->get('ids');
            $schema = $request->get('schema');

            $className = $this->convertSchemaToEntity($schema);
            /**
             * @var ObjectRepository $repository
             */
            $repository = $entityManager->getRepository($className);

            foreach ($ids as $id) {
                $element = $repository->find($id);

                if ($element) {
                    $entityManager->remove($element);
                }
            }

            $entityManager->flush();

            $this->sendSocketPool();

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
