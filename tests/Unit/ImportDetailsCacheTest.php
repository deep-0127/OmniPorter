<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use OmniPorter\Import\Helpers\ImportDetailsCache;
use Tests\TestCase;

/**
 * Unit tests for ImportDetailsCache helper methods that do NOT require Redis or a DB.
 * We test only the pure logic: isSimilar(), normalize(), and the static cache key.
 *
 * Methods that hit Redis (getInstance, persist, delete) are covered in Feature tests
 * via the array cache driver configured in phpunit.xml (CACHE_STORE=array).
 */
class ImportDetailsCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stub_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('stub_departments');
        parent::tearDown();
    }
    /**
     * We need an instance to call instance methods.
     * We use reflection to bypass the private constructor.
     */
    private function makeCache(): ImportDetailsCache
    {
        $reflection = new \ReflectionClass(ImportDetailsCache::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        // Set private properties we need for the pure-logic tests
        $prop = $reflection->getProperty('fillableFields');
        $prop->setAccessible(true);
        $prop->setValue($instance, []);

        $prop2 = $reflection->getProperty('relationDetailsList');
        $prop2->setAccessible(true);
        $prop2->setValue($instance, []);

        $prop3 = $reflection->getProperty('belongsToOneDetailsList');
        $prop3->setAccessible(true);
        $prop3->setValue($instance, []);

        $prop4 = $reflection->getProperty('belongsToManyDetailsList');
        $prop4->setAccessible(true);
        $prop4->setValue($instance, []);

        return $instance;
    }

    // ── normalize ─────────────────────────────────────────────────────────────

    public function test_normalize_strips_non_alpha_and_lowercases(): void
    {
        $cache = $this->makeCache();
        $this->assertSame('firstname', $cache->normalize('First_Name'));
    }

    public function test_normalize_singularizes(): void
    {
        $cache = $this->makeCache();
        // "roles" -> Str::singular -> "role"
        $this->assertSame('role', $cache->normalize('Roles'));
    }

    public function test_normalize_strips_spaces(): void
    {
        $cache = $this->makeCache();
        $this->assertSame('workmail', $cache->normalize('Work Mail'));
    }

    public function test_normalize_preserves_digits(): void
    {
        $cache = $this->makeCache();
        $this->assertSame('address1', $cache->normalize('Address 1'));
        $this->assertSame('address2', $cache->normalize('Address 2'));
    }

    // ── isSimilar ────────────────────────────────────────────────────────────

    public function test_is_similar_returns_true_for_identical_strings(): void
    {
        $cache = $this->makeCache();
        $this->assertTrue($cache->isSimilar('email', 'email'));
    }

    public function test_is_similar_returns_true_when_one_contains_other(): void
    {
        $cache = $this->makeCache();
        // "work_email" normalizes to "workemail"; "email" normalizes to "email"
        // "workemail" contains "email" → true
        $this->assertTrue($cache->isSimilar('work_email', 'email'));
    }

    public function test_is_similar_returns_true_for_plural_vs_singular(): void
    {
        $cache = $this->makeCache();
        // "Roles" → "role", "role" → "role" → identical → true
        $this->assertTrue($cache->isSimilar('Roles', 'role'));
    }

    public function test_is_similar_returns_false_for_unrelated_strings(): void
    {
        $cache = $this->makeCache();
        $this->assertFalse($cache->isSimilar('phone_number', 'salary'));
    }

    /**
     * Regression test for the known false-positive: "id" is contained in "middle_name" (after
     * normalization "middlename" does NOT contain "id" since "id" is not a substring — wait,
     * it is: "midd-le-name" has no 'id' substring. Let's confirm the actual behaviour.
     *
     * normalize("middle_name") = "middlename"  (no special chars)
     * normalize("id")          = "id"
     * str_contains("middlename", "id") = FALSE  ✓
     *
     * The *real* edge case is: normalize("position_id") = "positionid"
     * normalize("id") = "id"  →  str_contains("positionid", "id") = TRUE  ← false positive
     * This test documents the known limitation.
     */
    public function test_is_similar_known_false_positive_for_id_suffix(): void
    {
        $cache = $this->makeCache();
        // "position_id" and "id" ARE considered similar by the current algorithm
        // This is a documented edge case, not a regression.
        $this->assertTrue($cache->isSimilar('position_id', 'id'));
    }

    public function test_is_similar_case_insensitive(): void
    {
        $cache = $this->makeCache();
        $this->assertTrue($cache->isSimilar('Email', 'email'));
    }

    // ── initialize / relations ───────────────────────────────────────────────

    public function test_has_one_relation_not_registered_to_belongs_to_one_list(): void
    {
        $model = new class extends \Tests\Feature\Stubs\StubImportableModel {
            public static function getListOfRelationDetails(): array
            {
                return [
                    'profile' => [
                        'type' => 'hasOne',
                        'model' => \Tests\Feature\Stubs\StubDepartmentModel::class,
                        'method' => 'profile',
                    ]
                ];
            }
        };

        $cache = ImportDetailsCache::getInstance('test_batch_has_one', get_class($model), false);
        
        $list = $cache->getBelongsToOneDetailsList();
        $this->assertArrayNotHasKey('profile', $list);
    }

    // ── mapBelongsToManyHeadings ─────────────────────────────────────────────

    public function test_map_belongs_to_many_prioritizes_exact_match_over_fuzzy(): void
    {
        $data = [
            'batchId' => 'test_batch',
            'model' => \Tests\Feature\Stubs\StubImportableModel::class,
            'update' => false,
            'fillableFields' => [],
            'relationDetailsList' => [
                'roles' => [
                    'model' => \Tests\Feature\Stubs\StubDepartmentModel::class,
                    'type' => 'belongsToMany',
                    'method' => 'roles',
                    'pattern' => 'role',
                ]
            ],
            'belongsToOneDetailsList' => [],
            'belongsToManyDetailsList' => [
                'roles' => [
                    'model' => \Tests\Feature\Stubs\StubDepartmentModel::class,
                    'type' => 'belongsToMany',
                    'method' => 'roles',
                    'pattern' => 'role',
                    'headings' => []
                ]
            ],
            'fieldHeadingMap' => [],
            'headings' => ['user_roles', 'roles'], // exact is 2nd
            'validationFilePath' => null,
        ];

        $cache = ImportDetailsCache::fromArray($data);
        $cache->mapBelongsToManyHeadings();
        
        $list = $cache->getBelongsToManyDetailsList();
        // Since Exact pass goes first, 'roles' should be mapped first.
        // Wait, headings list processing: exact pass processes all headings.
        // So 'roles' matches exactly, is appended. Then fuzzy pass matches 'user_roles'.
        $this->assertEquals(['roles', 'user_roles'], $list['roles']['headings']);
    }
}
