<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Line options that are not sub-products.
 *
 * Kept apart from ProductOptionController on purpose: that one decides which
 * VARIANTS exist — things with their own stock — while these only change a
 * line's price, its ticket, and sometimes another article's stock.
 */
class ModifierController extends Controller
{
    public function storeGroup(Request $request): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        $data = $this->validateGroup($request, $tenant->id);

        ModifierGroup::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'min_select' => $data['min_select'] ?? 0,
            'max_select' => $data['max_select'] ?? null,
            'sort_order' => (int) ModifierGroup::where('tenant_id', $tenant->id)->max('sort_order') + 1,
        ]);

        return back()->with('status', 'Groupe « '.$data['name'].' » créé.');
    }

    public function updateGroup(Request $request, ModifierGroup $modifierGroup): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($modifierGroup->tenant_id === $tenant->id, 404);

        $modifierGroup->update($this->validateGroup($request, $tenant->id, $modifierGroup->id));

        return back()->with('status', 'Groupe mis à jour.');
    }

    public function destroyGroup(Request $request, ModifierGroup $modifierGroup): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($modifierGroup->tenant_id === $tenant->id, 404);

        // Retired rather than deleted once anything has been sold with it:
        // `sale_item_modifiers.modifier_id` nulls on delete, and the old
        // tickets would lose the thread back to what was offered.
        if ($this->hasBeenSold($modifierGroup)) {
            $modifierGroup->update(['is_active' => false]);

            return back()->with('status', 'Groupe désactivé : des ventes l’utilisent.');
        }

        $modifierGroup->delete();

        return back()->with('status', 'Groupe supprimé.');
    }

    public function storeModifier(Request $request, ModifierGroup $modifierGroup): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($modifierGroup->tenant_id === $tenant->id, 404);

        $data = $this->validateModifier($request, $tenant->id, $modifierGroup->id);

        Modifier::create([
            'tenant_id' => $tenant->id,
            'modifier_group_id' => $modifierGroup->id,
            'name' => $data['name'],
            'price_delta' => $data['price_delta'] ?? 0,
            'linked_item_id' => $data['linked_item_id'] ?? null,
            'consumes_quantity' => $data['consumes_quantity'] ?? 1,
            'sort_order' => (int) $modifierGroup->modifiers()->max('sort_order') + 1,
        ]);

        return back()->with('status', 'Option « '.$data['name'].' » ajoutée.');
    }

    public function updateModifier(Request $request, Modifier $modifier): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($modifier->tenant_id === $tenant->id, 404);

        $modifier->update(
            $this->validateModifier($request, $tenant->id, $modifier->modifier_group_id, $modifier->id),
        );

        return back()->with('status', 'Option mise à jour.');
    }

    public function destroyModifier(Request $request, Modifier $modifier): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($modifier->tenant_id === $tenant->id, 404);

        if ($modifier->saleLines()->exists()) {
            $modifier->update(['is_active' => false]);

            return back()->with('status', 'Option désactivée : des ventes l’utilisent.');
        }

        $modifier->delete();

        return back()->with('status', 'Option supprimée.');
    }

    /** Decides which articles offer a group. */
    public function assign(Request $request, ModifierGroup $modifierGroup): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($modifierGroup->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
        ]);

        $payload = [];
        foreach (array_values($data['item_ids'] ?? []) as $index => $itemId) {
            $payload[$itemId] = ['tenant_id' => $tenant->id, 'sort_order' => $index];
        }

        $modifierGroup->items()->sync($payload);

        return back()->with('status', 'Articles mis à jour pour « '.$modifierGroup->name.' ».');
    }

    /** @return array<string, mixed> */
    private function validateGroup(Request $request, int $tenantId, ?int $ignore = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120',
                Rule::unique('modifier_groups', 'name')->where('tenant_id', $tenantId)->ignore($ignore)],
            'min_select' => ['nullable', 'integer', 'min:0', 'max:20'],
            // Null means "as many as you like": a burger can take every
            // topping, and a ceiling invented here would be wrong for someone.
            'max_select' => ['nullable', 'integer', 'min:1', 'max:20', 'gte:min_select'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.unique' => 'Ce groupe existe déjà.',
            'max_select.gte' => 'Le maximum ne peut pas être inférieur au minimum.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validateModifier(Request $request, int $tenantId, int $groupId, ?int $ignore = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120',
                Rule::unique('modifiers', 'name')->where('modifier_group_id', $groupId)->ignore($ignore)],
            // Signed: "sans fromage -2 DH" is as real as a supplement.
            'price_delta' => ['nullable', 'numeric', 'between:-99999,99999'],
            // The article this eats into, for the few that do.
            'linked_item_id' => ['nullable', 'integer',
                Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            'consumes_quantity' => ['nullable', 'numeric', 'min:0.001', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.unique' => 'Cette option existe déjà dans ce groupe.',
        ]);
    }

    private function hasBeenSold(ModifierGroup $group): bool
    {
        return \App\Models\SaleItemModifier::whereIn(
            'modifier_id',
            $group->modifiers()->select('id'),
        )->exists();
    }
}
