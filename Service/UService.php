<?php

namespace Newageerp\SfUservice\Service;

use Newageerp\SfCrud\Interface\IConvertService;
use Newageerp\SfPermissions\Service\PermissionServiceInterface;
use Newageerp\SfAuth\Service\AuthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Ramsey\Uuid\Uuid;
use Newageerp\SfSerializer\Serializer\ObjectSerializer;

class UService
{
    protected PermissionServiceInterface $permissionService;

    protected IConvertService $convertService;

    protected EntityManagerInterface $em;

    public function __construct(
        PermissionServiceInterface $permissionService,
        IConvertService $convertService,
        EntityManagerInterface $em,
    ) {
        $this->permissionService = $permissionService;
        $this->convertService = $convertService;
        $this->em = $em;
    }

    protected function convertSchemaToEntity(string $schema)
    {
        $entityClass = implode('', array_map('ucfirst', explode("-", $schema)));

        return 'App\Entity\\' . $entityClass;
    }

    public function getListDataForSchema(
        string $schema,
        int $page,
        int $pageSize,
        array $fieldsToReturn,
        array $filters,
        array $extraData,
        array $sort,
        array $totals,
    ) {
        $user = AuthService::getInstance()->getUser();
        if (!$user) {
            throw new \Exception('Invalid user');
        }

        $className = $this->convertSchemaToEntity($schema);
        $this->permissionService->extendFilters($user, $filters, $schema);

        $classicMode = false;
        if (isset($filters[0]['classicMode'])) {
            $classicMode = $filters[0]['classicMode'];
            unset($filters[0]['classicMode']);
        }
        if (isset($filters[1]['classicMode'])) {
            $classicMode = $filters[1]['classicMode'];
            unset($filters[1]['classicMode']);
        }
        if (isset($filters[2]['classicMode'])) {
            $classicMode = $filters[2]['classicMode'];
            unset($filters[2]['classicMode']);
        }

        $debug = false;

        $totalData = [];
        $pagingData = [];
        $data = [];
        $query = null;

        if (isset($filters['empty']) && $filters['empty']) {
            $pagingData['c'] = 1;
            $entity = new $className();

            $createOptions = isset($extraData['createOptions']) ? $extraData['createOptions'] : [];

            $convertFieldsReturn = $this->convertService->convert(
                $entity,
                $schema,
                isset($createOptions['convert']) ? $createOptions['convert'] : [],
                $createOptions,
                $user
            );

            if ($fieldsToReturn && $convertFieldsReturn) {
                $fieldsToReturn = array_merge($fieldsToReturn, $convertFieldsReturn);
            }

            $data = [$entity];
        } else {
            $alias = 'i';

            $qb = $this->em->createQueryBuilder()
                ->select($alias)
                ->from($className, $alias, null);

            $log = [];

            $params = [];
            $joins = [];

            $debug = false;

            foreach ($filters as $filter) {
                $statements = $this->getStatementsFromFilters($qb, $className, $filter, $debug, $joins, $params, $classicMode);
                if ($statements && !$debug) {
                    $qb->andWhere($statements);
                }
            }

            if (!$debug) {
                foreach ($params as $key => $val) {
                    $qb->setParameter($key, $val);
                }

                foreach ($joins as $join => $alias) {
                    $qb->leftJoin($join, $alias);
                }
            }

            foreach ($sort as $sortEl) {
                [$subJoins, $mainAlias, $alias, $fieldKey, $uuid] = $this->joinsByKey($sortEl['key']);

                $qb->addOrderBy($alias . '.' . $fieldKey, $sortEl['value']);
                foreach ($subJoins as $join => $alias) {
                    $qb->leftJoin($join, $alias);
                }
            }

            $pagingQb = clone $qb;

            if ($page > 0) {
                $firstResult = ($page - 1) * $pageSize;
                $qb->setMaxResults($pageSize)
                    ->setFirstResult($firstResult);
            }

            $query = $qb->getQuery();

            $data = $query->getResult();
            $pagingQb
                ->select('count(i.id) as c');
            foreach ($totals as $total) {
                if ($total['type'] === 'count') {
                    $pagingQb->addSelect('count(' . $total['path'] . ') as ' . $total['field'] . '');
                } else {
                    $pagingQb->addSelect('sum(' . $total['path'] . ') as ' . $total['field'] . '');
                }
            }
            $pagingData = $pagingQb->getQuery()
                ->getSingleResult();


            foreach ($totals as $total) {
                $totalData[$total['field']] = isset($pagingData[$total['field']]) ? round((float)$pagingData[$total['field']], 2) : 0;
            }
        }
        $jsonContent = array_map(function ($item) use ($fieldsToReturn) {
            return ObjectSerializer::serializeRow($item, $fieldsToReturn);
        }, $data);

        return [
            'data' => $jsonContent,
            'records' => $pagingData['c'],
            'totals' => $totalData,
            'sql' => $query ? $query->getSQL() : '',
            'params' => $query ? $query->getParameters() : [],
            'filters' => $filters,
            // 'log' => $log,
            'cl' => $classicMode
        ];
    }


    protected function getStatementsFromFilters(
        QueryBuilder $qb,
                     $className,
        array $filter,
        bool $debug,
                     &$joins,
                     &$params,
                     $classicMode = false
    ) {
        $statements = null;
        $loopKey = '';
        if (isset($filter['and'])) {
            $statements = $qb->expr()->andX();
            $loopKey = 'and';
        } else if (isset($filter['or'])) {
            $statements = $qb->expr()->orX();
            $loopKey = 'or';
        }

        if ($statements) {
            foreach ($filter[$loopKey] as $st) {
                if (isset($st['or']) || isset($st['and'])) {
                    $subStatements = $this->getStatementsFromFilters($qb, $className, $st, $debug, $joins, $params, $classicMode);
                    $statements->add($subStatements);
                } else {
                    $fieldDirectSelect = isset($st[3]) ? $st[3] : $classicMode;

                    $value = $st[2];
                    if ($value === 'CURRENT_USER' && AuthService::getInstance()->getUser()) {
                        $value = AuthService::getInstance()->getUser()->getId();
                    }
                    $op = '';
                    $opIsNot = false;
                    $needBrackets = false;
                    $skipParams = false;

                    $pureSql = false;

                    if (isset($st[1]) && $st[1] === 'contains') {
                        $op = 'like';
                        $value = '%' . $st[2] . '%';
                    } else if (isset($st[1]) && ($st[1] === 'eq' || $st[1] === 'equal' || $st[1] === 'equals')) {
                        $op = 'like';
                        $value = $st[2];
                    } else if (isset($st[1]) && $st[1] === 'start') {
                        $op = 'like';
                        $value = $st[2] . '%';
                    } else if (isset($st[1]) && $st[1] === 'end') {
                        $op = 'like';
                        $value = '%' . $st[2];
                    } else if (isset($st[1]) && $st[1] === 'not_contains') {
                        if ($fieldDirectSelect) {
                            $op = 'not like';
                            $value = '%' . $st[2] . '%';
                        } else {
                            $op = 'like';
                            $opIsNot = true;
                            $value = '%' . $st[2] . '%';
                        }
                    } else if (isset($st[1]) && $st[1] === 'not_eq') {
                        if ($fieldDirectSelect) {
                            $op = 'not like';
                            $value = $st[2];
                        } else {
                            $op = 'like';
                            $opIsNot = true;
                            $value = $st[2];
                        }
                    } else if (isset($st[1]) && $st[1] === 'not_start') {
                        if ($fieldDirectSelect) {
                            $op = 'not like';
                            $value = $st[2] . '%';
                        } else {
                            $op = 'like';
                            $opIsNot = true;
                            $value = $st[2] . '%';
                        }
                    } else if (isset($st[1]) && $st[1] === 'not_end') {
                        if ($fieldDirectSelect) {
                            $op = 'not like';
                            $value = '%' . $st[2];
                        } else {
                            $op = 'like';
                            $value = '%' . $st[2];
                            $opIsNot = true;
                        }
                    } else if (isset($st[1]) && ($st[1] === 'num_eq' || $st[1] === '=')) {
                        $op = '=';
                    } else if (isset($st[1]) && ($st[1] === 'num_not_eq' || $st[1] === '!=')) {
                        $op = '!=';
                    } else if (isset($st[1]) && ($st[1] === 'gt' || $st[1] === '>')) {
                        $op = '>';
                    } else if (isset($st[1]) && ($st[1] === 'gte' || $st[1] === '>=')) {
                        $op = '>=';
                    } else if (isset($st[1]) && ($st[1] === 'lt' || $st[1] === '<')) {
                        $op = '<';
                    } else if (isset($st[1]) && ($st[1] === 'lte' || $st[1] === '<=')) {
                        $op = '<=';
                    } else if (isset($st[1]) && $st[1] === 'dgt') {
                        $op = '>';
                        $value = new \DateTime($st[2] . ' 00:00:00');
                    } else if (isset($st[1]) && $st[1] === 'dgte') {
                        $op = '>=';
                        $value = new \DateTime($st[2] . ' 00:00:00');
                    } else if (isset($st[1]) && $st[1] === 'dlt') {
                        $op = '<';
                        $value = new \DateTime($st[2] . ' 00:00:00');
                    } else if (isset($st[1]) && $st[1] === 'dlte') {
                        $op = '<=';
                        $value = new \DateTime($st[2] . ' 23:59:59');
                    } else if (isset($st[1]) && $st[1] === 'deq') {
                        $op = '=';
                        $value = new \DateTime($st[2]);
                    } else if (isset($st[1]) && $st[1] === 'not_deq') {
                        if ($fieldDirectSelect) {
                            $op = '!=';
                            $value = new \DateTime($st[2]);
                        } else {
                            $op = '=';
                            $opIsNot = true;
                            $value = new \DateTime($st[2]);
                        }
                    } else if (isset($st[1]) && $st[1] === 'in') {
                        $op = 'in';
                        $needBrackets = true;
                    } else if (isset($st[1]) && $st[1] === 'not_in') {
                        if ($fieldDirectSelect) {
                            $op = 'not in';
                            $needBrackets = true;
                        } else {
                            $op = 'in';
                            $opIsNot = true;
                            $needBrackets = true;
                        }
                    } else if (isset($st[1]) && ($st[1] === 'JSON_SEARCH' || $st[1] === 'JSON_CONTAINS' || $st[1] === 'JSON_NOT_CONTAINS' || $st[1] === 'IS_NOT_NULL' || $st[1] === 'IS_NULL')) {
                        $op = 'CUSTOM';
                        if ($st[1] === 'IS_NOT_NULL' || $st[1] === 'IS_NULL') {
                            $skipParams = true;
                        }
                    }

                    if ($op) {
                        [$subJoins, $mainAlias, $alias, $fieldKey, $uuid] = $this->joinsByKey($st[0]);

                        if (!$skipParams) {
                            $params[$uuid] = $value;
                        }

                        $subQ = $this->em->createQueryBuilder()
                            ->select($mainAlias)
                            ->from($className, $mainAlias, null);

                        // PURE SQL
                        $statement = '';

                        if (isset($st[1]) && $st[1] === 'IS_NULL') {
                            $statement = $qb->expr()->isNull($alias . '.' . $fieldKey);
                        } else if (isset($st[1]) && $st[1] === 'IS_NOT_NULL') {
                            $statement = $qb->expr()->isNotNull($alias . '.' . $fieldKey);
                        } else if (isset($st[1]) && $st[1] === 'JSON_CONTAINS') {
                            $statement = "JSON_CONTAINS(" . $alias . '.' . $fieldKey . ", :" . $uuid . ", '$') = 1";
                        } else if (isset($st[1]) && $st[1] === 'JSON_NOT_CONTAINS') {
                            $statement = "JSON_CONTAINS(" . $alias . '.' . $fieldKey . ", :" . $uuid . ", '$') != 1";
                        } else if (isset($st[1]) && $st[1] === 'JSON_SEARCH') {
                            $statement = "JSON_SEARCH(" . $alias . '.' . $fieldKey . ", 'one', :" . $uuid . ") IS NOT NULL";
                        } else {
                            $statement = $alias . '.' . $fieldKey . ' ' . $op . ' ';
                            if ($needBrackets) {
                                $statement .= '(';
                            }
                            $statement .= ':' . $uuid;
                            if ($needBrackets) {
                                $statement .= ')';
                            }
                        }


                        // foreach ($subParams as $key => $val) {
                        //     $subQ->setParameter($key, $val);
                        // }
                        // $this->ajLogger->warning('SUB JOIN open');
                        foreach ($subJoins as $join => $alias) {
                            if ($fieldDirectSelect) {
                                $qb->leftJoin($join, $alias);
                            } else {
                                $subQ->leftJoin($join, $alias);
                            }
                            // $this->ajLogger->warning('SUB JOIN ' . $join . ' ' . $alias);
                        }


                        if (!$debug) {
                            if ($fieldDirectSelect) {
                                if ($opIsNot) {
                                    $statements->add($qb->expr()->not($statement));
                                } else {
                                    $statements->add($statement);
                                }
                            } else {
                                $subQ->andWhere($statement);
                            }
                        }

                        if (!$fieldDirectSelect) {
                            if ($opIsNot) {
                                $statements->add($qb->expr()->not($qb->expr()->exists($subQ->getDQL())));
                            } else {
                                $statements->add($qb->expr()->exists($subQ->getDQL()));
                            }
                        }

                        $log['f'][] = $statement;
                    }
                }
            }
        }
        return $statements;
    }

    protected function joinsByKey($key)
    {
        $uuid = 'P' . str_replace('-', '', Uuid::uuid4()->toString());
        $mainAlias = 'A' . str_replace('-', '', Uuid::uuid4()->toString());

        $tmp = explode(".", $key);
        $alias = $tmp[0];
        $fieldKey = $tmp[count($tmp) - 1];
        $subJoins = [];
        for ($i = 1; $i < count($tmp) - 1; $i++) {
            $alias = implode("", array_slice($tmp, 0, $i + 1)) . $mainAlias;
            $join = implode("", array_slice($tmp, 0, $i)) . ($i > 1 ? $mainAlias : '') . '.' . $tmp[$i];
            $subJoins[$join] = $alias;
        }

        return [$subJoins, $mainAlias, $alias, $fieldKey, $uuid];
    }
}
