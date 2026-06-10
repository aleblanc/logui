<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Enforces architectural isolation: nothing under Aleblanc\LogUi\Core may import Symfony/Monolog/Doctrine.
 *
 * @implements Rule<Use_>
 */
final class CoreMustNotDependOnSymfonyRule implements Rule
{
    public function getNodeType(): string
    {
        return Use_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();
        if (null === $namespace || !str_starts_with($namespace, 'Aleblanc\\LogUi\\Core')) {
            return [];
        }

        $errors = [];
        foreach ($node->uses as $use) {
            $imported = $use->name->toString();
            foreach (['Symfony\\', 'Monolog\\', 'Doctrine\\'] as $forbidden) {
                if (str_starts_with($imported, $forbidden)) {
                    $errors[] = RuleErrorBuilder::message(sprintf('Core must not depend on %s (imported %s).', rtrim($forbidden, '\\'), $imported))
                        ->identifier('logui.coreIsolation')
                        ->build();
                }
            }
        }

        return $errors;
    }
}
