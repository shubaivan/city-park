<?php


namespace App\Repository;

use Symfony\Component\HttpFoundation\ParameterBag;

trait DataTablesApproachRepository
{
    /**
     * @param array $params
     * @return ParameterBag
     */
    public function handleDataTablesRequest(array $params): ParameterBag
    {
        $parameterBag = new ParameterBag();

        if (isset($params['order'])) {
            $columnIndex = $params['order'][0]['column']; // Column index
            $columnName = $params['columns'][$columnIndex]['data']; // Column name
            $columnSortOrder = $params['order'][0]['dir']; // asc or desc
        } else {
            $columnName = 'id';
            $columnSortOrder = 'desc';
        }

        $parameterBag->set('sort_by', $columnName);
        $parameterBag->set('sort_order', $columnSortOrder);

        if (isset($params['search']['value']) && strlen($params['search']['value'])) {
            $search = $params['search']['value'];
            $parameterBag->set('search', $search);
        }


        if (isset($params['draw'])) {
            $draw = $params['draw'];
            $parameterBag->set('page', $draw);
        }

        if (isset($params['start'])) {
            $offset = $params['start'];
            $parameterBag->set('offset', $offset);
        }

        if (isset($params['length'])) {
            $limit = $params['length'];
            $parameterBag->set('limit', $limit);
        }

        if (isset($params['columns']) && is_array($params['columns'])) {
            foreach ($params['columns'] as $column) {
                if (isset($column['search']['value'])
                    && isset($column['data'])
                    && strlen($column['search']['value'])
                ) {
                    $parameterBag->set($column['data'], $column['search']['value']);
                }
            }
        }

        return $parameterBag;
    }
}