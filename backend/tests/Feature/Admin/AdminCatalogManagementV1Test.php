<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B23 - Catalog Admin Management: Category/Service create,
 * category move, specialization, options/choices, media, the two-price
 * catalog block (original/current price via the canonical PricingEngine
 * draft->rule->publish flow), activation-safety prerequisites, and the four
 * end-to-end catalog scenarios from the phase spec.
 *
 * Layered on Tests\Feature\Admin\AdminServiceCatalogTest (Phase B8 read/
 * metadata/activate-deactivate coverage) and Tests\Feature\Admin\
 * AdminPricingTest (Phase B9 draft/rule/publish coverage) - this suite only
 * covers what Phase B23 itself adds.
 */
class AdminCatalogManagementV1Test extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // -----------------------------------------------------------------
    // Categories - create
    // -----------------------------------------------------------------

    public function test_admin_can_create_service_category(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->postJson('/api/v1/admin/service-categories', [
            'code' => 'QA_NEW_CAT',
            'name' => 'QA New Category',
            'description' => 'QA fixture.',
            'display_order' => 5,
            'is_active' => false,
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $categoryId = $response->json('data.service_category.id');
        $this->assertFalse($response->json('data.service_category.is_active'));

        $stored = DB::table('service_categories')->where('id', $categoryId)->first();
        $this->assertSame('QA_NEW_CAT', $stored->code);

        $this->assertSame(['SERVICE_CATEGORY_CREATED'], $this->auditLogsFor((string) $categoryId)->pluck('action_code')->all());
    }

    public function test_create_category_rejects_duplicate_code(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->postJson('/api/v1/admin/service-categories', ['code' => 'QA_DUP', 'name' => 'First'], $this->bearer($admin['access_token']))
            ->assertStatus(201);

        $this->postJson('/api/v1/admin/service-categories', ['code' => 'QA_DUP', 'name' => 'Second'], $this->bearer($admin['access_token']))
            ->assertStatus(422);
    }

    public function test_create_category_requires_name_and_code(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson('/api/v1/admin/service-categories', [], $this->bearer($admin['access_token']))
            ->assertStatus(422);
    }

    public function test_customer_cannot_create_service_category(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->postJson('/api/v1/admin/service-categories', ['code' => 'QA_X', 'name' => 'X'], $this->bearer($customer['access_token']))
            ->assertStatus(401);
    }

    public function test_search_filter_matches_category_name(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        DB::table('service_categories')->insert([
            'code' => 'QA_SEARCH_CAT',
            'name' => 'Deep Cleaning Category',
            'description' => 'QA fixture.',
            'display_order' => 900,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/admin/service-categories?search=Deep+Cleaning', $this->bearer($admin['access_token']));

        $this->assertContains('Deep Cleaning Category', collect($response->json('data.service_categories'))->pluck('name')->all());
    }

    // -----------------------------------------------------------------
    // Services - create + category move
    // -----------------------------------------------------------------

    public function test_admin_can_create_service_inactive_by_default(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();

        $response = $this->postJson('/api/v1/admin/services', [
            'category_id' => $categoryId,
            'code' => 'QA_NEW_SVC',
            'slug' => 'qa-new-svc',
            'name' => 'QA New Service',
            'short_description' => 'Short.',
            'description' => 'Long.',
            'display_order' => 3,
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $this->assertFalse($response->json('data.service.is_active'));
        $serviceUuid = $response->json('data.service.uuid');

        $this->assertSame(['SERVICE_CREATED'], $this->auditLogsFor($serviceUuid)->pluck('action_code')->all());

        // Not yet reachable via the public catalog while inactive.
        $this->getJson('/api/v1/services/qa-new-svc')->assertStatus(404);
    }

    public function test_create_service_rejects_duplicate_name_within_the_same_category(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $existing = $this->createCartService($categoryId);
        $existingName = DB::table('services')->where('id', UuidBinary::toBinary($existing['uuid']))->value('name');

        $this->postJson('/api/v1/admin/services', [
            'category_id' => $categoryId, 'code' => 'QA_DUP_NAME', 'slug' => 'qa-dup-name', 'name' => $existingName,
        ], $this->bearer($admin['access_token']))->assertStatus(422);
    }

    public function test_create_service_rejects_unknown_category(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson('/api/v1/admin/services', [
            'category_id' => 999999999,
            'code' => 'QA_X',
            'slug' => 'qa-x',
            'name' => 'QA X',
        ], $this->bearer($admin['access_token']))->assertStatus(422);
    }

    public function test_admin_can_move_service_to_another_category(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryA = $this->createCartCategory();
        $categoryB = $this->createCartCategory();
        $service = $this->createCartService($categoryA);

        $response = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/category', ['category_id' => $categoryB], $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $this->assertSame($categoryB, $response->json('data.service.category.id'));
        $this->assertSame(['SERVICE_CATEGORY_CHANGED'], $this->auditLogsFor($service['uuid'])->pluck('action_code')->all());
    }

    public function test_move_service_rejects_duplicate_name_in_target_category(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryA = $this->createCartCategory();
        $categoryB = $this->createCartCategory();
        $service = $this->createCartService($categoryA);

        DB::table('services')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'category_id' => $categoryB,
            'code' => 'QA_CONFLICT',
            'slug' => 'qa-conflict-slug',
            'name' => DB::table('services')->where('id', UuidBinary::toBinary($service['uuid']))->value('name'),
            'display_order' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/category', ['category_id' => $categoryB], $this->bearer($admin['access_token']))
            ->assertStatus(409);
    }

    public function test_customer_cannot_create_service(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $categoryId = $this->createCartCategory();

        $this->postJson('/api/v1/admin/services', [
            'category_id' => $categoryId, 'code' => 'X', 'slug' => 'x', 'name' => 'X',
        ], $this->bearer($customer['access_token']))->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Specializations
    // -----------------------------------------------------------------

    public function test_admin_can_set_and_update_service_specialization(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $specializationId = $this->createSpecialization();

        $set = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/specializations', [
            'specialization_id' => $specializationId, 'is_primary' => true, 'is_active' => true,
        ], $this->bearer($admin['access_token']));

        $set->assertStatus(200);
        $this->assertCount(1, $set->json('data.service.specializations'));

        // Idempotent upsert: demote to non-primary.
        $update = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/specializations', [
            'specialization_id' => $specializationId, 'is_primary' => false, 'is_active' => true,
        ], $this->bearer($admin['access_token']));

        $update->assertStatus(200);
        $this->assertFalse($update->json('data.service.specializations.0.is_primary'));
    }

    public function test_second_primary_specialization_is_rejected_with_409(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $specA = $this->createSpecialization();
        $specB = $this->createSpecialization();

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/specializations', [
            'specialization_id' => $specA, 'is_primary' => true, 'is_active' => true,
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/specializations', [
            'specialization_id' => $specB, 'is_primary' => true, 'is_active' => true,
        ], $this->bearer($admin['access_token']))->assertStatus(409);
    }

    public function test_admin_can_list_specializations_lookup(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $specializationId = $this->createSpecialization();

        $response = $this->getJson('/api/v1/admin/specializations', $this->bearer($admin['access_token']));

        $this->assertContains($specializationId, collect($response->json('data.specializations'))->pluck('id')->all());
    }

    // -----------------------------------------------------------------
    // Options / Choices
    // -----------------------------------------------------------------

    public function test_admin_can_create_and_edit_a_number_option(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $create = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/options', [
            'code' => 'QA_ROOMS', 'name' => 'Number of rooms', 'option_type_code' => 'NUMBER',
            'is_required' => true, 'display_order' => 1,
            'numeric_rule' => ['min_value' => 1, 'max_value' => 10],
        ], $this->bearer($admin['access_token']));

        $create->assertStatus(201);
        $option = collect($create->json('data.service.options'))->firstWhere('code', 'QA_ROOMS');
        $this->assertSame('NUMBER', $option['type']);
        $this->assertTrue($option['is_required']);

        $edit = $this->patchJson('/api/v1/admin/service-options/'.$option['uuid'], [
            'name' => 'Room count', 'is_required' => false, 'display_order' => 2,
            'numeric_rule' => ['min_value' => 2, 'max_value' => 20],
        ], $this->bearer($admin['access_token']));

        $edit->assertStatus(200);
        $edited = collect($edit->json('data.service.options'))->firstWhere('uuid', $option['uuid']);
        $this->assertSame('Room count', $edited['name']);
        $this->assertFalse($edited['is_required']);
        $this->assertSame('2.000000', $edited['numeric_rule']['min_value']);

        $this->assertSame(['SERVICE_OPTION_CREATED', 'SERVICE_OPTION_UPDATED'], $this->orderedAuditLogsFor($service['uuid']));
    }

    public function test_number_option_requires_numeric_rule(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/options', [
            'code' => 'QA_X', 'name' => 'X', 'option_type_code' => 'NUMBER', 'display_order' => 1,
        ], $this->bearer($admin['access_token']))->assertStatus(422);
    }

    public function test_admin_can_deactivate_and_reactivate_an_option(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $optionUuid = $this->createCartOption($service['uuid'], $this->numberTypeId);
        $this->createCartNumericRule($optionUuid);

        $deactivate = $this->postJson('/api/v1/admin/service-options/'.$optionUuid.'/deactivate', [], $this->bearer($admin['access_token']));
        $deactivate->assertStatus(200);
        $this->assertFalse(collect($deactivate->json('data.service.options'))->firstWhere('uuid', $optionUuid)['is_active']);

        $this->postJson('/api/v1/admin/service-options/'.$optionUuid.'/activate', [], $this->bearer($admin['access_token']))
            ->assertStatus(200);
    }

    public function test_admin_can_create_edit_and_deactivate_a_choice(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $optionUuid = $this->createCartOption($service['uuid'], $this->singleSelectTypeId);
        DB::table('service_option_selection_rules')->insert([
            'service_option_id' => UuidBinary::toBinary($optionUuid), 'minimum_selections' => 0, 'maximum_selections' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $create = $this->postJson('/api/v1/admin/service-options/'.$optionUuid.'/choices', [
            'code' => 'QA_SMALL', 'name' => 'Small', 'description' => 'Small size.', 'display_order' => 1,
        ], $this->bearer($admin['access_token']));

        $create->assertStatus(201);
        $option = collect($create->json('data.service.options'))->firstWhere('uuid', $optionUuid);
        $choiceUuid = $option['choices'][0]['uuid'];

        $edit = $this->patchJson('/api/v1/admin/service-option-choices/'.$choiceUuid, [
            'name' => 'Small (updated)', 'description' => 'Updated.', 'display_order' => 2,
        ], $this->bearer($admin['access_token']));
        $edit->assertStatus(200);
        $editedChoice = collect($edit->json('data.service.options'))->firstWhere('uuid', $optionUuid)['choices'][0];
        $this->assertSame('Small (updated)', $editedChoice['name']);

        $deactivate = $this->postJson('/api/v1/admin/service-option-choices/'.$choiceUuid.'/deactivate', [], $this->bearer($admin['access_token']));
        $deactivate->assertStatus(200);
        $this->assertFalse(collect($deactivate->json('data.service.options'))->firstWhere('uuid', $optionUuid)['choices'][0]['is_active']);

        $this->assertSame(
            ['SERVICE_OPTION_CHOICE_CREATED', 'SERVICE_OPTION_CHOICE_UPDATED', 'SERVICE_OPTION_CHOICE_DEACTIVATED'],
            $this->orderedAuditLogsFor($service['uuid']),
        );
    }

    /**
     * @return array<int, string>
     */
    private function orderedAuditLogsFor(string $entityIdentifier): array
    {
        return DB::table('admin_audit_logs')
            ->where('entity_identifier', $entityIdentifier)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('action_code')
            ->all();
    }

    public function test_choice_cannot_be_added_to_a_text_option(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $optionUuid = $this->createCartOption($service['uuid'], $this->textTypeId);

        $this->postJson('/api/v1/admin/service-options/'.$optionUuid.'/choices', [
            'code' => 'QA_X', 'name' => 'X',
        ], $this->bearer($admin['access_token']))->assertStatus(422);
    }

    public function test_choice_creation_rejects_duplicate_code_and_duplicate_name(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $optionUuid = $this->createCartOption($service['uuid'], $this->singleSelectTypeId);
        DB::table('service_option_selection_rules')->insert([
            'service_option_id' => UuidBinary::toBinary($optionUuid), 'minimum_selections' => 0, 'maximum_selections' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/admin/service-options/'.$optionUuid.'/choices', [
            'code' => 'QA_SMALL', 'name' => 'Small',
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        // Same code, different name.
        $this->postJson('/api/v1/admin/service-options/'.$optionUuid.'/choices', [
            'code' => 'QA_SMALL', 'name' => 'Different',
        ], $this->bearer($admin['access_token']))->assertStatus(409);

        // Same name, different code - would otherwise hit the raw
        // uq_service_option_choices_option_name constraint as an
        // unhandled 500 without an explicit check.
        $this->postJson('/api/v1/admin/service-options/'.$optionUuid.'/choices', [
            'code' => 'QA_DIFFERENT_CODE', 'name' => 'Small',
        ], $this->bearer($admin['access_token']))->assertStatus(409);
    }

    // -----------------------------------------------------------------
    // Media
    // -----------------------------------------------------------------

    private function fakeImageFile(string $name = 'photo.png'): UploadedFile
    {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $path = tempnam(sys_get_temp_dir(), 'qa_img').'.png';
        file_put_contents($path, $pngBytes);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function test_admin_can_upload_activate_and_deactivate_media(): void
    {
        Storage::fake('public');
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $upload = $this->post('/api/v1/admin/services/'.$service['uuid'].'/media', [
            'file' => $this->fakeImageFile(),
            'alt_text' => 'QA fixture image',
            'is_primary' => '1',
        ], $this->bearer($admin['access_token']));

        $upload->assertStatus(201);
        $media = $upload->json('data.service.media');
        $this->assertCount(1, $media);
        $this->assertTrue($media[0]['is_primary']);
        Storage::disk('public')->assertExists($media[0]['storage_key']);
        $this->assertStringNotContainsString(storage_path(), json_encode($media), 'Media response must never leak a filesystem path.');

        $mediaUuid = $media[0]['uuid'];

        $deactivate = $this->postJson('/api/v1/admin/service-media/'.$mediaUuid.'/deactivate', [], $this->bearer($admin['access_token']));
        $deactivate->assertStatus(200);
        $deactivated = $deactivate->json('data.service.media.0');
        $this->assertFalse($deactivated['is_active']);
        $this->assertFalse($deactivated['is_primary'], 'Deactivating the primary image must also clear the primary flag.');

        $this->postJson('/api/v1/admin/service-media/'.$mediaUuid.'/activate', [], $this->bearer($admin['access_token']))
            ->assertStatus(200);
    }

    public function test_media_upload_rejects_non_image_file(): void
    {
        Storage::fake('public');
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $textFile = UploadedFile::fake()->createWithContent('not-an-image.txt', 'plain text content');

        $this->post('/api/v1/admin/services/'.$service['uuid'].'/media', [
            'file' => $textFile,
            'alt_text' => 'QA fixture',
        ], $this->bearer($admin['access_token']))->assertStatus(422);
    }

    public function test_uploading_a_second_primary_image_demotes_the_first(): void
    {
        Storage::fake('public');
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->post('/api/v1/admin/services/'.$service['uuid'].'/media', [
            'file' => $this->fakeImageFile('first.png'), 'alt_text' => 'First', 'is_primary' => '1',
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $second = $this->post('/api/v1/admin/services/'.$service['uuid'].'/media', [
            'file' => $this->fakeImageFile('second.png'), 'alt_text' => 'Second', 'is_primary' => '1',
        ], $this->bearer($admin['access_token']));

        $second->assertStatus(201);
        $media = collect($second->json('data.service.media'));
        $this->assertSame(1, $media->where('is_primary', true)->count(), 'Only one image may be primary at a time.');
        $this->assertTrue($media->firstWhere('alt_text', 'Second')['is_primary']);
    }

    // -----------------------------------------------------------------
    // Pricing - original price
    // -----------------------------------------------------------------

    public function test_admin_can_set_original_price(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $response = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/original-price', ['original_price' => 199.99], $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $this->assertSame('199.990000', $response->json('data.service.pricing.original_amount'));
        $this->assertSame(['SERVICE_ORIGINAL_PRICE_CHANGED'], $this->auditLogsFor($service['uuid'])->pluck('action_code')->all());
    }

    public function test_original_price_rejects_negative_value(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/original-price', ['original_price' => -1], $this->bearer($admin['access_token']))
            ->assertStatus(422);
    }

    public function test_original_price_below_current_selling_price_is_rejected(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/current-price', ['current_price' => '150.00'], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/original-price', ['original_price' => 100], $this->bearer($admin['access_token']))
            ->assertStatus(422);
    }

    public function test_original_price_can_be_cleared_with_null(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/original-price', ['original_price' => 150], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $response = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/original-price', ['original_price' => null], $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $this->assertNull($response->json('data.service.pricing.original_amount'));
    }

    // -----------------------------------------------------------------
    // Pricing - current price (canonical PricingEngine publish flow)
    // -----------------------------------------------------------------

    public function test_current_price_requires_step_up(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/current-price', ['current_price' => '100.00'], $this->bearer($admin['access_token']))
            ->assertStatus(428);
    }

    public function test_current_price_rejects_zero_and_negative(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/current-price', ['current_price' => '0'], $this->bearer($admin['access_token']))
            ->assertStatus(422);
        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/current-price', ['current_price' => '-5'], $this->bearer($admin['access_token']))
            ->assertStatus(422);
    }

    public function test_current_price_above_original_price_is_rejected(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/original-price', ['original_price' => 100], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/current-price', ['current_price' => '150.00'], $this->bearer($admin['access_token']))
            ->assertStatus(422);
    }

    public function test_admin_can_set_current_price_and_it_publishes_through_the_canonical_pricing_flow(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $response = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/current-price', ['current_price' => '250.500000'], $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $this->assertSame('250.500000', $response->json('data.service.pricing.current_amount'));

        // Decimal-string precision, never a float, in the raw JSON.
        $this->assertStringContainsString('"current_amount":"250.500000"', $response->getContent());

        // Exactly one open-ended PUBLISHED version exists for this service+AED.
        $published = DB::table('pricing_scheme_versions')
            ->where('service_id', UuidBinary::toBinary($service['uuid']))
            ->where('status', 'PUBLISHED')
            ->whereNull('effective_to')
            ->get();
        $this->assertCount(1, $published);

        $this->assertSame(['SERVICE_CURRENT_PRICE_CHANGED'], $this->auditLogsFor($service['uuid'])->pluck('action_code')->all());
    }

    public function test_setting_current_price_again_retires_the_previous_published_version_and_replaces_the_effective_price(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/current-price', ['current_price' => '100.00'], $this->bearer($admin['access_token']))
            ->assertStatus(200);
        $firstVersionId = DB::table('pricing_scheme_versions')->where('service_id', UuidBinary::toBinary($service['uuid']))->where('status', 'PUBLISHED')->value('id');

        $second = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/current-price', ['current_price' => '120.00'], $this->bearer($admin['access_token']));
        $second->assertStatus(200);
        $this->assertSame('120.000000', $second->json('data.service.pricing.current_amount'));

        $firstVersion = DB::table('pricing_scheme_versions')->where('id', $firstVersionId)->first();
        $this->assertSame('RETIRED', $firstVersion->status);

        $publishedVersions = DB::table('pricing_scheme_versions')
            ->where('service_id', UuidBinary::toBinary($service['uuid']))
            ->where('status', 'PUBLISHED')
            ->whereNull('effective_to')
            ->count();
        $this->assertSame(1, $publishedVersions, 'Never two open-ended PUBLISHED versions for the same service+currency.');
    }

    /**
     * The AdminSetServiceCurrentPriceAction financial-correctness bug this
     * test guards against: a scheme with a pre-existing unconditional
     * SET_PRICE rule that is NOT literally rule_code='BASE_PRICE' (e.g.
     * authored via the advanced pricing-rule screen, or - as here - via the
     * generic Cart-fixture pricing rule builder) must not be blindly
     * carried forward, or App\Support\Pricing\PricingRuleEvaluator's
     * last-SET_PRICE-wins-by-priority-order semantics would let that old
     * rule silently overwrite the brand new current price back to the old
     * amount during evaluation.
     */
    public function test_current_price_change_actually_wins_over_a_pre_existing_unconditional_non_base_price_rule(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        // An unconditional SET_PRICE rule with an arbitrary rule_code and a
        // low priority number (fires AFTER a new priority-1 BASE_PRICE rule
        // unless correctly excluded from carry-forward).
        $schemeUuid = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($schemeUuid, ['rule_code' => 'LEGACY_RATE', 'priority' => 50, 'effect_amount' => '999.000000']);

        $response = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/current-price', ['current_price' => '150.00'], $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $this->assertSame('150.000000', $response->json('data.service.pricing.current_amount'));

        // What the real customer catalog would see is the SAME new price,
        // not the old unconditional rule's amount.
        $catalog = $this->getJson('/api/v1/services/'.$service['slug']);
        $this->assertSame('150.000000', $catalog->json('data.pricing_preview.unit_total'));
    }

    // -----------------------------------------------------------------
    // Activation safety
    // -----------------------------------------------------------------

    public function test_activation_is_blocked_when_category_is_inactive(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        DB::table('service_categories')->where('id', $categoryId)->update(['is_active' => 0]);
        $service = $this->createCartService($categoryId, ['is_active' => 0]);
        $this->makeServicePriceableAndSpecialized($service['uuid'], $admin['access_token']);

        $response = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/activate', [], $this->bearer($admin['access_token']));

        $response->assertStatus(422);
        $this->assertStringContainsString('category is inactive', implode(' ', $response->json('errors')));
    }

    public function test_activation_is_blocked_without_a_published_current_price(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId, ['is_active' => 0]);
        $specializationId = $this->createSpecialization();
        $this->linkServiceSpecialization($service['uuid'], $specializationId);

        $response = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/activate', [], $this->bearer($admin['access_token']));

        $response->assertStatus(422);
        $this->assertStringContainsString('no currently-published', implode(' ', $response->json('errors')));
    }

    public function test_activation_is_blocked_without_an_active_specialization(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId, ['is_active' => 0]);
        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/current-price', ['current_price' => '100.00'], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $response = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/activate', [], $this->bearer($admin['access_token']));

        $response->assertStatus(422);
        $this->assertStringContainsString('no active specialization', implode(' ', $response->json('errors')));
    }

    public function test_activation_is_blocked_when_a_required_select_option_has_no_active_choice(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId, ['is_active' => 0]);
        $this->makeServicePriceableAndSpecialized($service['uuid'], $admin['access_token']);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/options', [
            'code' => 'QA_REQUIRED_CHOICE', 'name' => 'Size', 'option_type_code' => 'SINGLE_SELECT',
            'is_required' => true, 'display_order' => 1,
            'selection_rule' => ['minimum_selections' => 1, 'maximum_selections' => 1],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $response = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/activate', [], $this->bearer($admin['access_token']));

        $response->assertStatus(422);
        $this->assertStringContainsString('has no active choice', implode(' ', $response->json('errors')));
    }

    public function test_fully_configured_service_activates_successfully(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId, ['is_active' => 0]);
        $this->makeServicePriceableAndSpecialized($service['uuid'], $admin['access_token']);

        $response = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/activate', [], $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.service.is_active'));
    }

    private function makeServicePriceableAndSpecialized(string $serviceUuid, string $adminAccessToken): void
    {
        $this->postJson('/api/v1/admin/services/'.$serviceUuid.'/current-price', ['current_price' => '100.00'], $this->bearer($adminAccessToken))
            ->assertStatus(200);

        $specializationId = $this->createSpecialization();
        $this->linkServiceSpecialization($serviceUuid, $specializationId);
    }

    // -----------------------------------------------------------------
    // Four end-to-end catalog scenarios (BLUE V1 Phase B23 spec)
    // -----------------------------------------------------------------

    public function test_scenario_1_full_admin_authored_service_reaches_the_customer_catalog(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);

        $category = $this->postJson('/api/v1/admin/service-categories', [
            'code' => 'QA_S1_CAT', 'name' => 'Scenario 1 Category', 'is_active' => false,
        ], $this->bearer($admin['access_token']))->json('data.service_category');

        $service = $this->postJson('/api/v1/admin/services', [
            'category_id' => $category['id'], 'code' => 'QA_S1_SVC', 'slug' => 'qa-s1-svc', 'name' => 'Scenario 1 Service',
        ], $this->bearer($admin['access_token']))->json('data.service');

        $this->assertFalse($service['is_active']);

        $specializationId = $this->createSpecialization();
        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/specializations', [
            'specialization_id' => $specializationId, 'is_primary' => true, 'is_active' => true,
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/original-price', ['original_price' => 150], $this->bearer($admin['access_token']))
            ->assertStatus(200);
        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/current-price', ['current_price' => '100.00'], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        // Category itself is still inactive - activation must fail first.
        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/activate', [], $this->bearer($admin['access_token']))
            ->assertStatus(422);

        $this->postJson('/api/v1/admin/service-categories/'.$category['id'].'/activate', [], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/activate', [], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $catalog = $this->getJson('/api/v1/services/qa-s1-svc');
        $catalog->assertStatus(200);
        $catalog->assertJsonPath('data.pricing.original_amount', '150.000000');
        $catalog->assertJsonPath('data.pricing.current_amount', '100.000000');
        $this->assertTrue($catalog->json('data.pricing.has_discount'));
    }

    public function test_scenario_2_price_updates_propagate_to_catalog_and_future_checkout(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $this->makeServicePriceableAndSpecialized($service['uuid'], $admin['access_token']);
        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/original-price', ['original_price' => 150], $this->bearer($admin['access_token']))
            ->assertStatus(200);
        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/activate', [], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/original-price', ['original_price' => 180], $this->bearer($admin['access_token']))
            ->assertStatus(200);
        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/current-price', ['current_price' => '120.00'], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $catalog = $this->getJson('/api/v1/services/'.$service['slug']);
        $catalog->assertJsonPath('data.pricing.original_amount', '180.000000');
        $catalog->assertJsonPath('data.pricing.current_amount', '120.000000');

        // Future Cart/Checkout pricing (the SAME PricingEngine call) reflects 120, not 100.
        $customer = $this->createAuthenticatedCartCustomer();
        $cartResponse = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1]);
        $cartResponse->assertStatus(201);
        $this->assertSame('120.000000', $cartResponse->json('data.cart.items.0.pricing.unit_total'));
    }

    public function test_scenario_3_price_change_never_mutates_a_pre_existing_booking(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $itemBefore = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $paymentBefore = DB::table('payment_attempts')->where('id', $fixture['payment']->id)->first();

        $this->postJson('/api/v1/admin/services/'.$fixture['service']['uuid'].'/current-price', ['current_price' => '999.00'], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $itemAfter = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $paymentAfter = DB::table('payment_attempts')->where('id', $fixture['payment']->id)->first();

        $this->assertSame((string) $itemBefore->line_total_amount, (string) $itemAfter->line_total_amount);
        $this->assertSame((string) $itemBefore->unit_total_amount, (string) $itemAfter->unit_total_amount);
        $this->assertSame((string) $itemBefore->base_amount_snapshot, (string) $itemAfter->base_amount_snapshot);
        $this->assertSame($itemBefore->pricing_breakdown, $itemAfter->pricing_breakdown);
        $this->assertSame(bin2hex($itemBefore->pricing_scheme_version_id), bin2hex($itemAfter->pricing_scheme_version_id));
        $this->assertSame((string) $paymentBefore->confirmed_amount, (string) $paymentAfter->confirmed_amount);
    }

    public function test_scenario_4_deactivated_service_unavailable_for_new_bookings_but_history_stays_readable(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $this->postJson('/api/v1/admin/services/'.$fixture['service']['uuid'].'/deactivate', [], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        // New Cart additions are refused (an inactive service resolves as
        // not-found for Cart purposes - App\Actions\Cart\AddCartItemAction
        // only ever looks up `is_active = 1` services).
        $newCustomer = $this->createAuthenticatedCartCustomer();
        $this->addCartItem($newCustomer['access_token'], ['service_uuid' => $fixture['service']['uuid'], 'quantity' => 1])
            ->assertStatus(404);

        // The historical Booking Item is still fully intact and readable.
        $itemStillThere = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $this->assertNotNull($itemStillThere);
        $this->assertSame((string) $fixture['item']->line_total_amount, (string) $itemStillThere->line_total_amount);
    }
}
