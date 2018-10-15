<?php declare(strict_types=1);

namespace Rector\Php\Rector\FuncCall;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use Rector\Printer\BetterStandardPrinter;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see https://www.tomasvotruba.cz/blog/2018/08/16/whats-new-in-php-73-in-30-seconds-in-diffs/#2-first-and-last-array-key
 *
 * This needs to removed 1 floor above, because only nodes in arrays can be removed why traversing,
 * see https://github.com/nikic/PHP-Parser/issues/389
 */
final class ArrayKeyFirstLastRector extends AbstractRector
{
    /**
     * @var BetterStandardPrinter
     */
    private $betterStandardPrinter;

    /**
     * @var string[]
     */
    private $previousToNewFunctions = [
        'reset' => 'array_key_first',
        'end' => 'array_key_last',
    ];

    public function __construct(BetterStandardPrinter $betterStandardPrinter)
    {
        $this->betterStandardPrinter = $betterStandardPrinter;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition(
            'Make use of array_key_first() and array_key_last()',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
reset($items);
$firstKey = key($items);
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
$firstKey = array_key_first($items);
CODE_SAMPLE
                ),
                new CodeSample(
<<<'CODE_SAMPLE'
end($items);
$lastKey = key($items);
CODE_SAMPLE
                    ,
<<<'CODE_SAMPLE'
$lastKey = array_key_last($items);
CODE_SAMPLE
                ),

            ]
        );
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [Function_::class, ClassMethod::class];
    }

    /**
     * @param Function_|ClassMethod $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->stmts === null) {
            return $node;
        }

        foreach ($node->stmts as $key => $stmt) {
            /** @var Expression $stmt */
            if (! $this->isFuncCallMatch($stmt->expr)) {
                continue;
            }

            if (! isset($node->stmts[$key + 1])) {
                continue;
            }

            if (! $this->isAssignMatch($node->stmts[$key + 1]->expr)) {
                continue;
            }

            $funcCallNode = $stmt->expr;

            /** @var FuncCall $funcCallNode */
            $currentFuncCallName = $this->getName($funcCallNode);

            /** @var Assign $assignNode */
            $assignNode = $node->stmts[$key + 1]->expr;

            /** @var FuncCall $assignFuncCallNode */
            $assignFuncCallNode = $assignNode->expr;

            if (! $this->areFuncCallNodesArgsEqual($funcCallNode, $assignFuncCallNode)) {
                continue;
            }

            // rename next method to new one
            $assignNode->expr->name = new Name($this->previousToNewFunctions[$currentFuncCallName]);

            // remove unused node
            unset($node->stmts[$key]);
        }

        // reindex for printer
        $node->stmts = array_values($node->stmts);

        return $node;
    }

    private function isFuncCallMatch(Node $node): bool
    {
        if (! $node instanceof FuncCall) {
            return false;
        }

        return $this->isNames($node, array_keys($this->previousToNewFunctions));
    }

    private function isAssignMatch(Node $node): bool
    {
        if (! $node instanceof Assign) {
            return false;
        }

        if (! $node->expr instanceof FuncCall) {
            return false;
        }

        return $this->isName($node->expr, 'key');
    }

    private function areFuncCallNodesArgsEqual(FuncCall $firstFuncCallNode, FuncCall $secondFuncCallNode): bool
    {
        if (! isset($firstFuncCallNode->args[0]) || ! isset($secondFuncCallNode->args[0])) {
            return false;
        }

        return $this->betterStandardPrinter->prettyPrint([$firstFuncCallNode->args[0]])
            === $this->betterStandardPrinter->prettyPrint([$secondFuncCallNode->args[0]]);
    }
}