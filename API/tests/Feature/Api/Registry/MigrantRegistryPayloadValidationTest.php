<?php

namespace Tests\Feature\Api\Registry;

use App\Http\Requests\StoreMigrantRegistryRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MigrantRegistryPayloadValidationTest extends TestCase
{
    public function test_official_intake_payload_is_valid(): void
    {
        $request = $this->requestWithPayload($this->validPayload());

        $request->validateResolved();

        $this->assertArrayNotHasKey('age', $request->validated('payload_json'));
        $this->assertSame('Honduras', $request->validated('payload_json.countryOfOrigin'));
    }

    public function test_age_does_not_need_to_be_submitted(): void
    {
        $request = $this->requestWithPayload($this->validPayload());

        $request->validateResolved();

        $this->assertArrayNotHasKey('age', $request->validated('payload_json'));
    }

    public function test_population_group_must_match_calculated_age(): void
    {
        $payload = $this->validPayload();
        $payload['populationGroup'] = 'unaccompanied_minor';

        $errors = $this->validationErrors($payload);

        $this->assertArrayHasKey('payload_json.populationGroup', $errors);
    }

    public function test_required_official_fields_are_enforced(): void
    {
        $payload = $this->validPayload();
        $payload['departmentState'] = '';

        $errors = $this->validationErrors($payload);

        $this->assertArrayHasKey('payload_json.departmentState', $errors);
    }

    public function test_second_last_name_can_be_blank(): void
    {
        $payload = $this->validPayload();
        $payload['secondLastName'] = '';
        $payload['fullName'] = 'John Doe';
        $request = $this->requestWithPayload($payload);

        $request->validateResolved();

        $this->assertSame('', $request->validated('payload_json.secondLastName'));
        $this->assertSame('John Doe', $request->validated('payload_json.fullName'));
    }

    public function test_name_parts_accept_multiple_words_and_outer_whitespace(): void
    {
        $payload = $this->validPayload();
        $payload['firstName'] = '  Maria del Carmen  ';
        $payload['firstLastName'] = '  De la Cruz  ';
        $payload['secondLastName'] = '';
        $payload['fullName'] = 'Maria del Carmen De la Cruz';
        $request = $this->requestWithPayload($payload);

        $request->validateResolved();

        $this->assertSame('Maria del Carmen', $request->validated('payload_json.firstName'));
        $this->assertSame('De la Cruz', $request->validated('payload_json.firstLastName'));
    }

    public function test_invalid_name_error_explains_allowed_characters(): void
    {
        $payload = $this->validPayload();
        $payload['firstName'] = 'Test 1';

        $errors = $this->validationErrors($payload);

        $this->assertSame(
            'The first name may contain only letters, spaces, apostrophes, periods, and hyphens.',
            $errors['payload_json.firstName'][0],
        );
    }

    public function test_full_name_must_match_name_parts(): void
    {
        $payload = $this->validPayload();
        $payload['fullName'] = 'Different Name';

        $errors = $this->validationErrors($payload);

        $this->assertArrayHasKey('payload_json.fullName', $errors);
    }

    public function test_multipart_registration_decodes_payload_and_accepts_supported_documents(): void
    {
        $request = $this->multipartRequest([
            UploadedFile::fake()->create('identification.pdf', 32, 'application/pdf'),
        ]);

        $request->validateResolved();

        $this->assertSame('John Doe X', $request->validated('payload_json.fullName'));
        $this->assertCount(1, $request->validated('documents'));
    }

    public function test_multipart_registration_rejects_unsupported_document_types(): void
    {
        try {
            $this->multipartRequest([
                UploadedFile::fake()->create('program.exe', 32, 'application/x-msdownload'),
            ])->validateResolved();
            $this->fail('Executable attachments should not pass migrant document validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents.0', $exception->errors());
        }
    }

    public function test_multipart_registration_rejects_documents_when_the_feature_is_disabled(): void
    {
        config()->set('features.migrant_documents', false);

        try {
            $this->multipartRequest([
                UploadedFile::fake()->create('identification.pdf', 32, 'application/pdf'),
            ])->validateResolved();
            $this->fail('Attachments should not pass validation while migrant documents are disabled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }
    }

    /** @param array<string, mixed> $payload */
    private function requestWithPayload(array $payload): StoreMigrantRegistryRequest
    {
        $request = StoreMigrantRegistryRequest::create('/registry/migrants', 'POST', [
            'payload_json' => $payload,
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));

        return $request;
    }

    /** @param list<UploadedFile> $documents */
    private function multipartRequest(array $documents): StoreMigrantRegistryRequest
    {
        $request = StoreMigrantRegistryRequest::create(
            '/registry/migrants',
            'POST',
            [
                'payload_json' => json_encode($this->validPayload(), JSON_THROW_ON_ERROR),
                'document_labels' => ['Identification'],
            ],
            [],
            ['documents' => $documents],
        );
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));

        return $request;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private function validationErrors(array $payload): array
    {
        try {
            $this->requestWithPayload($payload)->validateResolved();
            $this->fail('The migrant registry payload should have failed validation.');
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'attentionDate' => '2026-07-14',
            'birthDate' => '1996-03-31',
            'civilStatus' => 'single',
            'countryOfOrigin' => 'Honduras',
            'departmentState' => 'Cortes',
            'firstLastName' => 'Doe',
            'firstName' => 'John',
            'fullName' => 'John Doe X',
            'gender' => 'male',
            'notes' => '',
            'phone' => '+52 81 3100 8716',
            'populationGroup' => 'adult',
            'secondLastName' => 'X',
        ];
    }
}
