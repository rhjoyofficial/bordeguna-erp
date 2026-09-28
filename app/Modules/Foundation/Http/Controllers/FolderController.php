<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\DocumentFolder;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The document archive's folders (Joy, 28 Sep 2026, "like Google Drive").
 *
 * ⚠ Organisation only. A folder never decides who may see a file - that stays
 * with FileVisibility, keyed on the record a file belongs to. These endpoints
 * only shape the tree; the files.manage permission gates all of them (see the
 * route group). Route-model binding on {folder} is business-scoped through the
 * model's global scope, so another business's folder 404s rather than 403s.
 */
class FolderController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', $this->parentRule()],
        ]);

        $parentId = $data['parent_id'] ?? null;

        if ($this->nameTaken($data['name'], $parentId)) {
            return back()->withErrors(['name' => 'There is already a folder with that name here.']);
        }

        DocumentFolder::create([
            'business_id' => app(BusinessContext::class)->id(),
            'parent_id' => $parentId,
            'name' => $data['name'],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', "Folder \"{$data['name']}\" created.");
    }

    public function update(Request $request, DocumentFolder $folder): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            // Present-but-null means "move to the top level"; absent means "leave
            // where it is". $request->has distinguishes the two below.
            'parent_id' => ['nullable', $this->parentRule($folder)],
        ]);

        $name = $data['name'] ?? $folder->name;
        $parentId = $request->has('parent_id') ? ($data['parent_id'] ?? null) : $folder->parent_id;

        // A folder cannot move inside its own subtree, which would strand the branch.
        if ($request->has('parent_id') && $parentId !== null) {
            $target = DocumentFolder::find($parentId);
            if ($folder->wouldCycleInto($target)) {
                return back()->withErrors(['parent_id' => 'A folder cannot be moved inside itself.']);
            }
        }

        if ($this->nameTaken($name, $parentId, $folder->getKey())) {
            return back()->withErrors(['name' => 'There is already a folder with that name here.']);
        }

        $folder->update(['name' => $name, 'parent_id' => $parentId]);

        return back()->with('success', 'Folder updated.');
    }

    public function destroy(DocumentFolder $folder): RedirectResponse
    {
        // ⚠ Empty only. Deleting a folder full of files or subfolders is how an
        // archive quietly loses things; the person empties it first, where each
        // move or deletion is its own visible act.
        if ($folder->children()->exists() || $folder->files()->exists()) {
            return back()->with('error', 'That folder is not empty. Move or remove what is inside it first.');
        }

        $name = $folder->name;
        $folder->delete();

        return back()->with('success', "Folder \"{$name}\" deleted.");
    }

    /**
     * A parent must be a folder in THIS business. When editing, it may not be the
     * folder itself (the deeper cycle check is in update()).
     */
    private function parentRule(?DocumentFolder $editing = null): Exists
    {
        $rule = Rule::exists('document_folders', 'id')->where('business_id', app(BusinessContext::class)->id());

        return $editing !== null ? $rule->whereNot('id', $editing->getKey()) : $rule;
    }

    /**
     * Is a sibling folder already using this name? Enforced here rather than by a
     * unique index alone because MySQL treats null parents (the root) as distinct,
     * so the index cannot catch duplicate top-level names.
     */
    private function nameTaken(string $name, ?int $parentId, ?int $ignoreId = null): bool
    {
        return DocumentFolder::query()
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }
}
