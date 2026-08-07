<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Documents\DocumentVerificationPackageController;
use ReflectionClass;
use Tests\TestCase;

class DocumentVerificationPackageTemplateTest extends TestCase
{
    public function test_offline_verifier_contains_a_persistent_complete_language_switch(): void
    {
        $reflection = new ReflectionClass(DocumentVerificationPackageController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('verifyHtmlTemplate');
        $html = $method->invoke($controller);

        $this->assertIsString($html);
        $this->assertStringContainsString('data-locale="en"', $html);
        $this->assertStringContainsString('data-locale="es"', $html);
        $this->assertStringContainsString('localeStorageKey = \'casamonarca.verifier.locale\'', $html);
        $this->assertStringContainsString('verificationPassed: \'Verification passed\'', $html);
        $this->assertStringContainsString('verificationPassed: \'Verificación correcta\'', $html);
        $this->assertStringContainsString('curpBinding: \'CURP signed binding\'', $html);
        $this->assertStringContainsString('curpBinding: \'Vinculación firmada de CURP\'', $html);
        $this->assertStringContainsString('document.documentElement.lang = locale', $html);
    }

    public function test_offline_verifier_distinguishes_a_manifest_backed_presentation_from_the_original_revision(): void
    {
        $reflection = new ReflectionClass(DocumentVerificationPackageController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('verifyHtmlTemplate');
        $html = $method->invoke($controller);

        $this->assertIsString($html);
        $this->assertStringContainsString("file.role === 'signature-presentation'", $html);
        $this->assertStringContainsString("file.presentationMode === 'merged'", $html);
        $this->assertStringContainsString("tr('presentationFileHash')", $html);
        $this->assertStringContainsString("tr('presentationNotice')", $html);
        $this->assertStringContainsString('const expectedOriginalHash = bundle.revision?.sha256', $html);
        $this->assertStringContainsString('intent.revisionSha256 === expectedOriginalHash', $html);
        $this->assertStringContainsString('evidenceFile?.canonicalSha256 === embeddedEvidenceHash', $html);
        $this->assertStringNotContainsString('intent.revisionSha256 === fileHash', $html);
    }
}
