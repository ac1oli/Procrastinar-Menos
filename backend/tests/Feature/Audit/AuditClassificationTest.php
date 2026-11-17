<?php

namespace Tests\Feature\Audit;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Todo evento de auditoria precisa ser classificado em config/retention.php:
 * retido (anonimizado) ou excluído na exclusão de conta.
 */
class AuditClassificationTest extends TestCase
{
    public function test_every_audit_action_used_in_code_is_classified(): void
    {
        $retained = config('retention.audit_logs.retained_on_account_deletion');
        $notRetained = config('retention.audit_logs.not_retained_on_account_deletion');

        $this->assertSame([], array_values(array_intersect($retained, $notRetained)), 'Ação nas duas listas.');

        $actions = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            // Primeiro argumento de ->record(...), inclusive ternários.
            preg_match_all('/->record\(\s*([^,]+),/', $file->getContents(), $calls);
            foreach ($calls[1] as $argument) {
                preg_match_all("/'([a-z_]+\\.[a-z_.]+)'/", $argument, $literals);
                $actions = [...$actions, ...$literals[1]];
            }
        }

        $this->assertNotEmpty($actions);
        $this->assertSame(
            [],
            array_values(array_diff(array_unique($actions), $retained, $notRetained)),
            'Classifique estes eventos em config/retention.php.',
        );
    }
}
