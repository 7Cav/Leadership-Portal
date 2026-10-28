<?php

namespace App\Http\Controllers;

use App\Commands\Templates\InvalidTemplateException;
use App\Commands\Templates\PickSectionTemplate;
use App\Commands\Templates\TemplateForbiddenException;
use App\Repositories\UnitRepository;
use App\Rollcall\TemplateResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * A section leader picks the template their rollcall post uses (#375, AD-17), from the ones the company switched on
 * and the regiment default, or clears the pick (`template_id` null) to use the company's. One audited Command, gated
 * by the section's write scope (AD-8); a view-as preview is refused before it gets here (`ReadOnlyWhileViewingAs`).
 *
 * Answers JSON, like the rest of the rollcall's background writes: on success the template the section now resolves to
 * and the picker as it now stands, so the page takes the server's word; 403 outside scope, 422 for a template the
 * section may not use.
 */
class RollcallTemplateController extends Controller
{
    public function __invoke(Request $request, int $unit, PickSectionTemplate $command, UnitRepository $units, TemplateResolver $resolver): JsonResponse
    {
        $validator = Validator::make($request->all(), ['template_id' => ['present', 'nullable', 'integer']]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            $command->handle($request->user(), $unit, $request->filled('template_id') ? (int) $request->input('template_id') : null);
        } catch (TemplateForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidTemplateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error saving the template pick.'], 500);
        }

        $section = $units->find($unit);

        return response()->json([
            'template' => $resolver->forUnit($section)->toArray(),
            'templatePicker' => $resolver->pickerFor($section),
        ]);
    }
}
