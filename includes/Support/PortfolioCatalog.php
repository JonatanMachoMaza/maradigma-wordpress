<?php

declare(strict_types=1);

namespace Maradigma\Support;

use Maradigma\ExternalApiClient;
use RuntimeException;

/** Resolves ordered portfolio references through the published boat search. */
final class PortfolioCatalog
{
    // Group_Services::GROUP_SERVICES_ID_BOATS in the external API.
    public const BOATS_GROUP_ID = 2;
    public const PAGE_SIZE = 100;

    public static function normalizeUuid(string $value): ?string
    {
        $value = \strtolower(\trim($value));
        return \preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $value) === 1
            ? $value : null;
    }

    /**
     * The Select2/combobox "id" is the public UUID, never the internal database ID.
     * @param list<array<string,mixed>> $rows
     * @return list<array{id:string,text:string}>
     */
    public static function choices(array $rows): array
    {
        $choices = [];
        foreach ($rows as $row) {
            $uuid = \is_string($row['uuid'] ?? null) ? self::normalizeUuid($row['uuid']) : null;
            if ($uuid !== null) {
                $choices[] = ['id' => $uuid, 'text' => (string) ($row['name'] ?? $uuid)];
            }
        }
        return $choices;
    }

    /**
     * Reads every page, preserving the API's ordering. Failures never yield a partial collection.
     * @param callable(int,int):array<string,mixed> $fetch
     * @return list<array<string,mixed>>
     */
    public static function readPages(callable $fetch): array
    {
        $rows = [];
        $offset = 0;
        do {
            $response = $fetch(self::PAGE_SIZE, $offset);
            if (($response['status'] ?? '') !== 'success' || !\is_array($response['data'] ?? null)) {
                throw new RuntimeException('Unable to read portfolio page.');
            }
            $page = $response['data'];
            $limit = (int) ($response['pagination']['limit'] ?? self::PAGE_SIZE);
            if ($limit < 1 || (int) ($response['pagination']['offset'] ?? $offset) !== $offset) {
                throw new RuntimeException('Invalid portfolio pagination.');
            }
            foreach ($page as $row) {
                if (!\is_array($row)) {
                    throw new RuntimeException('Invalid portfolio reference.');
                }
                $rows[] = $row;
            }
            $offset += \count($page);
        } while (\count($page) >= $limit);

        return $rows;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function search(ExternalApiClient $client, string $uuid, array $filters): array
    {
        $uuid = self::normalizeUuid($uuid);
        if ($uuid === null) {
            throw new RuntimeException('Invalid portfolio UUID.');
        }
        $portfolio = $client->getGroupPortfolio($uuid);
        if (($portfolio['status'] ?? '') !== 'success' || !\is_array($portfolio['data'] ?? null)) {
            throw new RuntimeException('Unable to load portfolio.');
        }
        if (!\is_string($portfolio['data']['uuid'] ?? null)
            || self::normalizeUuid($portfolio['data']['uuid']) !== $uuid) {
            throw new RuntimeException('Unexpected portfolio UUID.');
        }
        if ((int) ($portfolio['data']['id_group'] ?? 0) !== self::BOATS_GROUP_ID) {
            throw new RuntimeException('The portfolio must contain boats.');
        }
        $items = self::readPages(fn (int $limit, int $offset): array => $client->getGroupPortfolioItems(
            $uuid, ['limit' => $limit, 'offset' => $offset]
        ));
        $ids = [];
        foreach ($items as $item) {
            if (!\is_string($item['uuid_portfolio'] ?? null)
                || self::normalizeUuid($item['uuid_portfolio']) !== $uuid) {
                throw new RuntimeException('Unexpected portfolio membership UUID.');
            }
            if ((int) ($item['id_group'] ?? 0) !== self::BOATS_GROUP_ID) {
                throw new RuntimeException('Invalid portfolio service group.');
            }
            $id = (int) ($item['id_group_item'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = \array_values($ids);
        if (\array_key_exists('ids_gi', $filters)) {
            $selected = Sanitizer::castBoatsSearchValue('int[]', $filters['ids_gi']);
            $ids = \is_array($selected) ? \array_values(\array_intersect($ids, $selected)) : [];
        }
        $offset = \max(0, (int) ($filters['offset_services'] ?? 0));
        $limit = \max(1, (int) ($filters['limit_services'] ?? 10));
        unset($filters['portfolio'], $filters['ids_gi'], $filters['id_tenant'], $filters['tenant_id']);
        $filters['id_group'] = 'boats';
        $matches = [];
        $summary = [];
        $scanOffset = 0;
        while ($ids !== []) {
            $result = $client->getBoatsList(\array_merge($filters, [
                'limit_services' => self::PAGE_SIZE,
                'offset_services' => $scanOffset,
            ]));
            $data = $result['data'] ?? [];
            if (empty($result['success']) || !\is_array($data['search_result'] ?? null)) {
                throw new RuntimeException('Unable to resolve portfolio services.');
            }
            $rows = $data['search_result'];
            if ($summary === []) {
                $summary = $data;
                unset($summary['search_result']);
            }
            foreach (SelectedBoats::pick($rows, $ids) as $row) {
                $matches[SelectedBoats::idOf($row)] = $row;
            }
            $scanOffset += \count($rows);
            if (\count($matches) === \count($ids) || $rows === [] || $scanOffset >= (int) ($data['total_results'] ?? 0)) {
                break;
            }
            if ((int) ($data['offset'] ?? -1) + \count($rows) !== $scanOffset) {
                throw new RuntimeException('Invalid service pagination.');
            }
        }
        $matches = \array_values($matches);
        if ((int) ($filters['order_by'] ?? 0) === 0) {
            $matches = SelectedBoats::sortByList($matches, $ids);
        }
        $result = SelectedBoats::result($matches, $offset, $limit, $summary);
        $result['data']['calculated_page'] = (int) \floor($offset / $limit) + 1;
        return $result;
    }
}
