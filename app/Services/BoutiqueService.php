<?php

namespace App\Services;

use App\Enums\StatutBoutique;
use App\Models\Boutique;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Boutiques des propriétaires, gérées par l'administrateur de la plateforme.
 */
class BoutiqueService
{
    private const LOGO_DISK = 'public';
    private const LOGO_DIRECTORY = 'boutiques/logos';

    public function list(int $perPage, string $search, ?int $tenantId = null, ?string $statut = null, bool $withTrashed = false): LengthAwarePaginator
    {
        return Boutique::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->with('tenant:id,name')
            ->withCount('gerants')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($statut, fn ($q) => $q->where('statut', $statut))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('nom', "%{$search}%")
                ->orWhereLike('telephone', "%{$search}%")
                ->orWhereLike('quartier', "%{$search}%")
                ->orWhereLike('ville', "%{$search}%")))
            ->latest()
            ->paginate($perPage);
    }

    public function find(int|string $id): Boutique
    {
        return Boutique::with('tenant:id,name')->withCount('gerants')->findOrFail($id);
    }

    public function create(array $data): Boutique
    {
        $logo = $data['logo'] ?? null;
        unset($data['logo'], $data['remove_logo']);

        $data['slug'] = $this->uniqueSlug($data['nom']);
        $data['statut'] ??= StatutBoutique::Actif->value;

        if ($logo instanceof UploadedFile) {
            $data['logo_path'] = $logo->store(self::LOGO_DIRECTORY, self::LOGO_DISK);
        }

        return $this->find(Boutique::create($data)->id);
    }

    public function update(int|string $id, array $data): Boutique
    {
        $boutique = Boutique::findOrFail($id);

        $logo = $data['logo'] ?? null;
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        unset($data['logo'], $data['remove_logo']);

        // Le propriétaire ne change pas : ses gérants et ses clients sont rattachés à la boutique
        unset($data['tenant_id']);

        if ($logo instanceof UploadedFile) {
            $this->deleteLogo($boutique->logo_path);
            $data['logo_path'] = $logo->store(self::LOGO_DIRECTORY, self::LOGO_DISK);
        } elseif ($removeLogo) {
            $this->deleteLogo($boutique->logo_path);
            $data['logo_path'] = null;
        }

        $boutique->update($data);

        return $this->find($boutique->id);
    }

    public function delete(int|string $id): Boutique
    {
        $boutique = Boutique::findOrFail($id);
        $boutique->delete();

        return $boutique;
    }

    public function restore(int|string $id): Boutique
    {
        $boutique = Boutique::onlyTrashed()->findOrFail($id);
        $boutique->restore();

        return $this->find($boutique->id);
    }

    /** Adresse publique du logo, ou null. */
    public static function logoUrl(?string $path): ?string
    {
        return $path ? Storage::disk(self::LOGO_DISK)->url($path) : null;
    }

    /** Identifiant lisible et unique (« boutique-fall-medina-k3f9 »). */
    private function uniqueSlug(string $nom): string
    {
        $base = Str::limit(Str::slug($nom), 60, '') ?: 'boutique';

        do {
            $slug = $base . '-' . Str::lower(Str::random(4));
        } while (Boutique::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }

    private function deleteLogo(?string $path): void
    {
        if ($path && Storage::disk(self::LOGO_DISK)->exists($path)) {
            Storage::disk(self::LOGO_DISK)->delete($path);
        }
    }
}
