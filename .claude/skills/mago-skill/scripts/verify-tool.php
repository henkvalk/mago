<?php
/**
 * Checks that a Mago tool is registered, prints its definition, runs it and reports returned fields
 * that its field classification does not declare.
 *
 * Run from the Magento root.
 * Usage: php verify-tool.php <tool_name> ['<json params>'] [--allow-write]
 */
declare(strict_types=1);

use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;

$args = array_values(array_filter(array_slice($argv, 1), static fn ($arg) => $arg !== '--allow-write'));
$allowWrite = in_array('--allow-write', $argv, true);
$toolName = $args[0] ?? '';
$params = isset($args[1]) ? json_decode($args[1], true, 512, JSON_THROW_ON_ERROR) : [];

if ($toolName === '') {
    fwrite(STDERR, "Usage: php verify-tool.php <tool_name> ['<json params>'] [--allow-write]\n");
    exit(2);
}

$bootstrap = getcwd() . '/app/bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "FAIL: no app/bootstrap.php in " . getcwd() . ". Run this from the Magento root.\n");
    exit(2);
}
require $bootstrap;

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode(Area::AREA_ADMINHTML);

$registry = $objectManager->get(ToolRegistry::class);
$tool = $registry->getToolByName($toolName);

if ($tool === null) {
    fwrite(STDERR, "FAIL: tool '$toolName' is not in the ToolRegistry. Check di.xml and module:status.\n");
    exit(1);
}

$output = static fn (mixed $value): string => json_encode(
    $value,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";

echo "Registered: " . get_class($tool) . "\n";
echo "Name matches: " . ($tool->getName() === $toolName ? 'yes' : 'NO, getName() returns ' . $tool->getName()) . "\n";
echo "ACL (empty input): " . ($tool->getMagentoAcl() ?: '(none)') . "\n";
echo "Read-only: " . ($tool->isReadOnlyAction($params) ? 'yes' : 'no') . "\n";
echo "Definition:\n" . $output($registry->getToolDefinition($tool, null));

if (!$tool->isReadOnlyAction($params) && !$allowWrite) {
    echo "Skipped execute(): this call writes. Pass --allow-write to run it.\n";
    exit(0);
}

$result = $tool->execute($params);
echo "Result:\n" . $output($result);

/**
 * Mirrors PrivacyFilter::apply(): lists the paths of scalar values that are dropped only because no
 * rule covers them. Explicit STRIP rules and the always-allowed "error" key are not reported.
 */
$findUndeclared = static function (array $node, array $classes, ?array $inherited, string $path) use (&$findUndeclared): array {
    $found = [];
    foreach ($node as $key => $value) {
        $keyStr = is_string($key) ? $key : null;
        $rule = $keyStr !== null ? ($classes[$keyStr] ?? $classes[PiiClass::ANY] ?? null) : $inherited;
        $childPath = $path === '' ? (string)$key : $path . '.' . $key;

        if ($rule !== null && $rule[0] === PiiClass::STRIP) {
            continue;
        }
        if (is_array($value)) {
            $found = [...$found, ...$findUndeclared($value, $classes, $keyStr !== null ? $rule : $inherited, $childPath)];
            continue;
        }
        if ($rule === null && $keyStr !== 'error') {
            $found[] = $childPath;
        }
    }

    return $found;
};

$action = (string)($params['action'] ?? '');
$undeclared = $findUndeclared($result, $tool->getFieldClassification($action), null, '');

if ($undeclared !== []) {
    echo "FAIL: undeclared fields, stripped before the model sees them: " . implode(', ', $undeclared) . "\n";
    exit(1);
}

echo "OK: every returned field is classified.\n";
