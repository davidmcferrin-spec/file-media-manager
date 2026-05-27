<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\ConversionRuleRepository;
use MediaManager\Repositories\MediaTypeRepository;

final class MediaTypeResolver
{
    /** @var list<array<string, mixed>> */
    private array $types;

    /** @var array<string, int> label => media_type_id */
    private array $aliasToId = [];

    public function __construct(
        private readonly MediaTypeRepository $mediaTypes = new MediaTypeRepository(),
        private readonly ConversionRuleRepository $conversions = new ConversionRuleRepository(),
    ) {
        $this->types = $mediaTypes->all(true);
        $this->buildAliasMap();
    }

    /** @return array<string, mixed>|null */
    public function resolve(string $label): ?array
    {
        $key = $this->normalizeKey($label);
        if ($key === '') {
            return null;
        }

        foreach ($this->types as $type) {
            if ($this->normalizeKey((string) $type['name']) === $key
                || $this->normalizeKey((string) $type['abbreviation']) === $key
                || $this->normalizeKey((string) ($type['folder_name'] ?? '')) === $key) {
                return $type;
            }
        }

        $id = $this->aliasToId[$key] ?? null;

        return $id !== null ? $this->findById($id) : null;
    }

    public function folderNameFor(string $label): ?string
    {
        $type = $this->resolve($label);

        return $type !== null ? (string) ($type['folder_name'] ?? $type['name']) : null;
    }

    public function abbreviationFor(string $label): ?string
    {
        $type = $this->resolve($label);

        return $type !== null ? (string) $type['abbreviation'] : null;
    }

    /** @return array{dir: string, filename: string, media_type_id: ?int}|null */
    public function normalizeProposal(string $suggestedDir, string $suggestedFilename, string $mediaTypeLabel): ?array
    {
        if (ProposalFilenameParser::isTemplate($suggestedDir, $suggestedFilename)) {
            return null;
        }

        $type = $this->resolve($mediaTypeLabel);
        if ($type === null) {
            return null;
        }

        $dir = trim(str_replace('\\', '/', $suggestedDir), '/');
        $parts = $dir !== '' ? explode('/', $dir) : [];
        if (count($parts) >= 4) {
            $parts[3] = (string) ($type['folder_name'] ?? $type['name']);
            $dir = implode('/', $parts);
        }

        $filename = $this->normalizeFilenameToken($suggestedFilename, (string) $type['abbreviation']);

        return [
            'dir'            => $dir,
            'filename'       => $filename,
            'media_type_id'  => (int) $type['id'],
        ];
    }

    private function normalizeFilenameToken(string $filename, string $abbreviation): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $parsed = ProposalFilenameParser::parseFilename($filename);
        if ($parsed['show_abbr'] === null || $parsed['date'] === null) {
            return $filename;
        }

        $time = $parsed['time'] ?? '0000';
        if ($parsed['media_token'] === 'GISO' && $parsed['guest'] !== null) {
            $base = sprintf(
                '%s_%s_%s_GISO_%s',
                $parsed['show_abbr'],
                $parsed['date'],
                $time,
                $parsed['guest']
            );
        } else {
            $base = sprintf(
                '%s_%s_%s_%s',
                $parsed['show_abbr'],
                $parsed['date'],
                $time,
                strtoupper($abbreviation)
            );
        }

        return $ext !== '' ? $base . '.' . $ext : $base;
    }

    private function buildAliasMap(): void
    {
        $builtIn = [
            'pgm'         => 'program',
            'programfeed' => 'program',
            'liveclean'   => 'clean',
            'pretape'     => 'clean',
            'pre-tape'    => 'clean',
        ];
        foreach ($builtIn as $alias => $target) {
            $type = $this->resolve($target);
            if ($type !== null) {
                $this->aliasToId[$alias] = (int) $type['id'];
            }
        }

        foreach ($this->conversions->all() as $rule) {
            if (($rule['category'] ?? '') !== 'media_type' || empty($rule['active'])) {
                continue;
            }
            $alias = $this->normalizeKey((string) $rule['alias']);
            $id = (int) ($rule['media_type_id'] ?? 0);
            if ($alias !== '' && $id > 0) {
                $this->aliasToId[$alias] = $id;
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function findById(int $id): ?array
    {
        foreach ($this->types as $type) {
            if ((int) $type['id'] === $id) {
                return $type;
            }
        }

        return null;
    }

    private function normalizeKey(string $label): string
    {
        return strtolower(preg_replace('/[\s_-]+/', '', trim($label)) ?? trim($label));
    }
}
