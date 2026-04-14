<?php

namespace App\Services\FirebaseMigration;

/**
 * Resolves listing → category Firestore document id using the required priority list.
 */
class CategoryReferenceResolver
{
    /**
     * Paths / keys in order (first non-empty wins).
     *
     * @var list<string>
     */
    protected const PRIORITY_PATHS = [
        'subcategoryid',
        'subcategory_id',
        'servicesubcategoryid',
        'service_subcategory_id',
        'subcategory.id',
        'category.subcategoryid',
        'servicecategory.id',
        'mainservicecategory',
        'categoryid',
        'category_id',
        'servicecategoryid',
        'category.id',
        'category.categoryid',
        'categorydocid',
    ];

    public function __construct(
        protected MigrationState $state
    ) {}

    /**
     * @param  array<string, mixed>  $listingData  Normalized UTF-8 listing payload
     */
    public function resolveLocalCategoryId(array $listingData): ?int
    {
        $flat = $this->flattenKeysLower($listingData);
        foreach (self::PRIORITY_PATHS as $path) {
            $v = str_contains($path, '.')
                ? $this->dataGetInsensitive($listingData, $path)
                : ($flat[$path] ?? null);
            $id = $this->normalizeCategoryRef($v);
            if ($id === null) {
                continue;
            }
            $local = $this->state->getCategoryId($id);
            if ($local !== null) {
                return $local;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function flattenKeysLower(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k)) {
                $out[strtolower($k)] = $v;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function dataGetInsensitive(array $data, string $dotPath): mixed
    {
        $parts = explode('.', $dotPath);
        $cursor = $data;
        foreach ($parts as $segment) {
            if (! is_array($cursor)) {
                return null;
            }
            $lower = strtolower($segment);
            $next = null;
            foreach ($cursor as $k => $v) {
                if (is_string($k) && strtolower($k) === $lower) {
                    $next = $v;
                    break;
                }
            }
            if ($next === null) {
                return null;
            }
            $cursor = $next;
        }

        return $cursor;
    }

    protected function normalizeCategoryRef(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v) || is_numeric($v)) {
            $s = trim((string) $v);

            return $s === '' ? null : $s;
        }
        if (is_array($v) && isset($v['id'])) {
            return $this->normalizeCategoryRef($v['id']);
        }

        return null;
    }
}
