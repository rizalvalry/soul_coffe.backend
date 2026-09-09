<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each menu is CALLED — and only that.
 *
 * WHY A SEPARATE TABLE INSTEAD OF EDITABLE ENUM LABELS
 * ----------------------------------------------------
 * Every menu in this panel has two different things that look like a name:
 *
 *   the KEY   — `products`, `carts`, `sales`… — which the access matrix stores, the code
 *               branches on, and the tests assert against. Change it and permissions silently
 *               detach from the menus they were granted for.
 *   the LABEL — "Produk", "Gerobak", "Penjualan Gerobak" — which is copy, and which the people
 *               using this panel should be free to rewrite in their own words.
 *
 * This table holds ONLY the second. `module` stores the key, which never changes; `label` is what
 * an operator typed. So renaming "Penjualan Gerobak" to "Kasir Gerobak" changes one string on one
 * screen and touches nothing else: not `role_permissions`, not a route, not a policy, not a test.
 * That separation is the whole feature — the request was for names that can be changed without
 * disturbing the flow underneath them.
 *
 * `scope` distinguishes an individual menu from a navigation GROUP heading, because both are
 * names an operator sees and both were asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_labels', function (Blueprint $table): void {
            $table->id();

            // 'module' = one menu entry, 'group' = a navigation heading.
            $table->string('scope', 16)->default('module');

            // The stable key: a PanelModule value, one of the few non-matrix menu keys, or a
            // group's canonical name. Deliberately a plain string rather than a foreign key —
            // the authoritative list lives in code (MenuLabels::catalogue()), and a row for a key
            // that no longer exists is ignored rather than being a broken reference.
            $table->string('module', 64);

            $table->string('label', 120);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scope', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_labels');
    }
};
