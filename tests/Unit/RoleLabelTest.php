<?php

namespace Tests\Unit;

use App\Enums\Role;
use PHPUnit\Framework\TestCase;

class RoleLabelTest extends TestCase
{
    public function test_produksi_label_keeps_barista_role_code(): void
    {
        $this->assertSame('BARISTA', Role::BARISTA->value);
        $this->assertSame('Produksi', Role::BARISTA->label());
    }
}
