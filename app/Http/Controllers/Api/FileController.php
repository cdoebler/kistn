<?php

namespace App\Http\Controllers\Api;

use App\Enums\EcosystemEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProjectFilesRequest;
use App\Models\Project;
use App\Services\PackageFileSanitizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    /** @var array<string, string> Maps multipart field name to storage filename */
    private const array FILE_MAP = [
        'installed_json' => 'installed.json.gz',
        'composer_lock' => 'composer.lock.gz',
        'composer_json' => 'composer.json.gz',
        'package_lock' => 'package-lock.json.gz',
        'package_json' => 'package.json.gz',
    ];

    public function __construct(
        private readonly PackageFileSanitizerService $sanitizer,
    ) {}

    public function store(StoreProjectFilesRequest $request, Project $project): JsonResponse
    {
        Log::debug('FileController@store: request received', [
            'project_uuid' => $project->uuid,
            'files' => array_keys($request->allFiles()),
        ]);

        $disk = Storage::disk('local');

        foreach ($request->allFiles() as $fieldName => $file) {
            $filename = self::FILE_MAP[$fieldName] ?? null;

            if ($filename !== null && $file instanceof UploadedFile) {
                $original = (string) file_get_contents($file->getRealPath());

                // Original stays verbatim under org/ for forensics; the top-level working copy is
                // sanitized so package managers never execute client-supplied lifecycle hooks.
                $disk->put("projects/{$project->uuid}/org/{$filename}", $original);
                $disk->put("projects/{$project->uuid}/{$filename}", $this->sanitizer->sanitize($filename, $original));

                Log::debug('FileController@store: file stored', [
                    'project_uuid' => $project->uuid,
                    'field' => $fieldName,
                    'stored_as' => $filename,
                ]);
            } else {
                Log::warning('FileController@store: skipped unknown file field', [
                    'project_uuid' => $project->uuid,
                    'field' => $fieldName,
                ]);
            }
        }

        foreach (EcosystemEnum::cases() as $ecosystem) {
            $jobClass = $ecosystem->auditJobClass();
            if ($jobClass !== null && $jobClass::shouldDispatch($project)) {
                dispatch(new $jobClass($project));
            }
        }

        return response()->json([], 202);
    }
}
