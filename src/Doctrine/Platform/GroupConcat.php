<?php
declare(strict_types=1);

namespace App\Doctrine\Platform;

use Doctrine\ORM\Query\AST\Node;
use App\Doctrine\Functions\GroupConcat as Base;
use Doctrine\ORM\Query\SqlWalker;

class GroupConcat
{
    public $parameters;

    public function __construct(array $parameters)
    {
        $this->parameters = $parameters;
    }

    /**
     * Get expression value string.
     *
     * @param string|Node $expression
     */
    protected function getExpressionValue($expression, SqlWalker $sqlWalker): string
    {
        if ($expression instanceof Node) {
            $expression = $expression->dispatch($sqlWalker);
        }

        return $expression;
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $isDistinct = !empty($this->parameters[Base::DISTINCT_KEY]);
        $result = 'array_to_string(array_agg(' . ($isDistinct ? 'DISTINCT ' : '');

        $fields = [];
        /** @var Node[] $pathExpressions */
        $pathExpressions = $this->parameters[Base::PARAMETER_KEY];
        foreach ($pathExpressions as $pathExp) {
            $fields[] = $pathExp->dispatch($sqlWalker);
        }

        if (\count($fields) === 1) {
            $concatenatedFields = \reset($fields);
        } else {
            $platform = $sqlWalker->getConnection()->getDatabasePlatform();
            $concatenatedFields = \call_user_func_array([$platform, 'getConcatExpression'], $fields);
        }
        $result .= $concatenatedFields;

        if (!empty($this->parameters[Base::ORDER_KEY])) {
            $result .= ' ' . $sqlWalker->walkOrderByClause($this->parameters[Base::ORDER_KEY]);
        }

        $result .= ')';

        if (isset($this->parameters[Base::SEPARATOR_KEY])) {
            $separator = $this->parameters[Base::SEPARATOR_KEY];
        } else {
            $separator = ',';
        }

        $result .= ', ' . $sqlWalker->walkStringPrimary($separator);

        $result .= ')';

        return $result;
    }
}
