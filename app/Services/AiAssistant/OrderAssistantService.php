<?php

namespace App\Services\AiAssistant;

use App\Models\SheetOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OrderAssistantService
{
    /**
     * @var list<string>
     */
    private array $defaultOrderTableHeaders = [
        'Order No',
        'Status',
        'Merchant',
        'Code',
        'Order Date',
        'Delivery Date',
        'Product',
        'Phone',
        'City',
    ];

    /**
     * @var list<string>
     */
    private array $searchCommandWords = [
        'order',
        'orders',
        'show',
        'list',
        'find',
        'lookup',
        'search',
        'check',
        'analyze',
        'analysis',
        'summary',
        'report',
        'does',
        'how many',
        'count',
        'number of',
    ];

    /**
     * @return array{
     *   reply: string,
      *   model: string,
      *   intent: string,
      *   fallback_used: bool,
      *   warnings: list<string>,
     *   context: array{boost: bool, retrieval_chunks: int},
     *   meta?: array<string, mixed>
     * }|null
     */
    public function respond(string $message, array $history = []): ?array
    {
        if (! $this->canHandle($message)) {
            return null;
        }

        $fieldReply = $this->buildSingleOrderFieldReply($message, $history);
        if ($fieldReply !== null) {
            return $this->wrapReply($fieldReply);
        }

        if ($this->needsDateClarification($message)) {
            return $this->wrapReply(implode("\n", [
                '## Date Filter Needed',
                'Please confirm the date filter before I search.',
                '- Which field: `order date` or `delivery date`?',
                '- Which value: a single date like `2026-03-10` or a range like `2026-03-01 to 2026-03-10`?',
                '',
                'Example: `show delivered orders by delivery date 2026-03-10`',
            ]));
        }

        [$reply, $table] = $this->shouldAnalyze($message)
            ? $this->buildAnalysisReply($message, $history)
            : ($this->shouldCount($message)
                ? $this->buildCountReply($message, $history)
                : $this->buildListingReply($message, $history));

        return $this->wrapReply($reply, $table);
    }

    public function canHandle(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        if ($normalized === '') {
            return false;
        }

        if ($this->extractOrderNumber($message) !== null) {
            return true;
        }

        if (preg_match('/\border(s)?\b/i', $message) === 1) {
            return true;
        }

        return preg_match(
            '/\b(order|orders|status|merchant|cc email|cc_email|code|delivery|product|phone|alt no|alt_no|city|address)\b/i',
            $message
        ) === 1;
    }

    private function shouldAnalyze(string $message): bool
    {
        return preg_match('/\b(analyze|analysis|summary|summarize|stats|statistics|report)\b/i', $message) === 1;
    }

    private function shouldCount(string $message): bool
    {
        return preg_match('/\b(how many|count|total number|number of)\b/i', $message) === 1;
    }

    /**
     * @return array{string, array<string, mixed>|null}
     */
    private function buildCountReply(string $message, array $history = []): array
    {
        $builder = $this->buildBaseQuery($message, $history);
        $count = (clone $builder)->count();
        $amount = (float) ((clone $builder)->sum('amount') ?? 0);

        if ($count === 0) {
            return [implode("\n", [
                '## Order Count',
                'No orders matched that query in `sheet_orders`.',
            ]), null];
        }

        $orders = (clone $builder)
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
        $table = $this->buildOrdersTablePayload($orders);

        return [implode("\n", [
            '## Order Count',
            "Matching orders: **{$count}**",
            'Total amount: **'.$this->formatMoney($amount).'**',
        ]), $table];
    }

    /**
     * @return array{string, array<string, mixed>|null}
     */
    private function buildListingReply(string $message, array $history = []): array
    {
        $builder = $this->buildBaseQuery($message, $history);
        $orders = (clone $builder)
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        if ($orders->isEmpty()) {
            return [implode("\n", [
                '## Order Results',
                'No orders matched that query in `sheet_orders`.',
                'Try queries like `show orders`, `show delivered orders`, or `show order UC001`.',
            ]), null];
        }

        $count = (clone $builder)->count();
        $amount = (float) ((clone $builder)->sum('amount') ?? 0);
        $table = $this->buildOrdersTablePayload($orders);

        return [implode("\n", [
            '## Order Results',
            "Found **{$count}** matching orders.",
            'Total amount: **'.$this->formatMoney($amount).'**',
        ]), $table];
    }

    /**
     * @return array{string, array<string, mixed>|null}
     */
    private function buildAnalysisReply(string $message, array $history = []): array
    {
        $builder = $this->buildBaseQuery($message, $history);
        $count = (clone $builder)->count();

        if ($count === 0) {
            return [implode("\n", [
                '## Order Analysis',
                'No orders matched that query in `sheet_orders`.',
            ]), null];
        }

        $amount = (float) ((clone $builder)->sum('amount') ?? 0);
        $quantity = (int) ((clone $builder)->sum('quantity') ?? 0);
        $statusBreakdown = (clone $builder)
            ->selectRaw("COALESCE(NULLIF(status, ''), 'Unknown') as status_label")
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('status_label')
            ->orderByDesc('total_orders')
            ->limit(8)
            ->get();
        $sample = (clone $builder)
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
        $table = $this->buildOrdersTablePayload($sample);

        return [implode("\n", [
            '## Order Analysis',
            "- Matching orders: **{$count}**",
            '- Total amount: **'.$this->formatMoney($amount).'**',
            "- Total quantity: **{$quantity}**",
            '',
            '### Status Breakdown',
            $this->formatStatusTable($statusBreakdown),
        ]), $table];
    }

    private function buildBaseQuery(string $message, array $history = []): Builder
    {
        $builder = SheetOrder::query();

        $orderNo = $this->extractOrderNumber($message);
        if ($orderNo === null && $this->shouldUseContextualOrderNumber($message)) {
            $orderNo = $this->extractContextualOrderNumber($message, $history);
        }

        if ($orderNo !== null) {
            return $builder->where('order_no', strtoupper($orderNo));
        }

        $status = $this->extractStatus($message);
        if ($status !== null) {
            $builder->whereRaw('LOWER(status) = ?', [mb_strtolower($status)]);
        }

        $fieldFiltersApplied = $this->applyFieldFilters($builder, $message);

        if (! $fieldFiltersApplied) {
            $this->applyFreeTextSearch($builder, $message);
        }

        $this->applyDateFilters($builder, $message);

        return $builder;
    }

    private function buildSingleOrderFieldReply(string $message, array $history = []): ?string
    {
        $field = $this->extractRequestedField($message);
        $orderNo = $this->extractContextualOrderNumber($message, $history);

        if ($field === null || $orderNo === null) {
            return null;
        }

        $order = SheetOrder::query()
            ->where('order_no', $orderNo)
            ->first();

        if ($order === null) {
            return null;
        }

        $label = $field['label'];
        $column = $field['column'];
        $value = $this->escapeCell((string) ($order->{$column} ?? ''));

        return implode("\n", [
            '## Order Detail',
            "Order **{$order->order_no}**",
            "{$label}: **{$value}**",
        ]);
    }

    private function applyFieldFilters(Builder $builder, string $message): bool
    {
        $fieldMap = [
            'merchant' => 'merchant',
            'cc_email' => 'cc_email',
            'cc email' => 'cc_email',
            'code' => 'code',
            'product name' => 'product_name',
            'product' => 'product_name',
            'phone' => 'phone',
            'alt no' => 'alt_no',
            'alt_no' => 'alt_no',
            'alternate number' => 'alt_no',
            'city' => 'city',
            'address' => 'address',
        ];

        $applied = false;

        foreach ($fieldMap as $label => $column) {
            $value = $this->extractFieldValue($message, $label);

            if ($value !== null) {
                $builder->where($column, 'like', "%{$value}%");
                $applied = true;
            }
        }

        return $applied;
    }

    private function applyFreeTextSearch(Builder $builder, string $message): void
    {
        $search = $this->extractSearchTerm($message);

        if ($search === null) {
            return;
        }

        $builder->where(function (Builder $query) use ($search): void {
            $query
                ->where('order_no', 'like', "%{$search}%")
                ->orWhere('client_name', 'like', "%{$search}%")
                ->orWhere('product_name', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('alt_no', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%")
                ->orWhere('store_name', 'like', "%{$search}%")
                ->orWhere('merchant', 'like', "%{$search}%")
                ->orWhere('cc_email', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%");
        });
    }

    private function applyDateFilters(Builder $builder, string $message): void
    {
        foreach (['order date' => 'order_date', 'delivery date' => 'delivery_date'] as $label => $column) {
            $range = $this->extractDateRange($message, $label);

            if ($range !== null) {
                [$from, $to] = $range;
                $builder->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);
                continue;
            }

            $date = $this->extractSingleDate($message, $label);
            if ($date !== null) {
                $builder->whereDate($column, '=', $date);
            }
        }
    }

    private function extractOrderNumber(string $message): ?string
    {
        if (preg_match('/\b([a-z]{1,5}\d{2,})\b/i', $message, $matches) !== 1) {
            return null;
        }

        return strtoupper((string) $matches[1]);
    }

    private function extractContextualOrderNumber(string $message, array $history = []): ?string
    {
        $current = $this->extractOrderNumber($message);

        if ($current !== null) {
            return $current;
        }

        foreach (array_reverse($history) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $content = isset($item['content']) && is_string($item['content'])
                ? $item['content']
                : null;

            if ($content === null || trim($content) === '') {
                continue;
            }

            $fromHistory = $this->extractOrderNumber($content);

            if ($fromHistory !== null) {
                return $fromHistory;
            }
        }

        return null;
    }

    private function shouldUseContextualOrderNumber(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        if ($normalized === '') {
            return false;
        }

        if ($this->shouldCount($message) || $this->shouldAnalyze($message)) {
            return false;
        }

        if (preg_match('/\b(cancelled|canceled|delivered|returned|expired|pending|confirmed|remitted|processing)\b/i', $message) === 1) {
            return false;
        }

        if (preg_match('/\b(merchant|cc email|cc_email|code|delivery date|order date|product name|product|phone|alt no|alt_no|city|address)\b/i', $message) === 1) {
            return false;
        }

        if (preg_match('/\b(that order|this order|the order|see that order|show that order|open that order|check that order|show it|check it|called it|is it)\b/i', $message) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return array{column: string, label: string}|null
     */
    private function extractRequestedField(string $message): ?array
    {
        $normalized = mb_strtolower($message);

        $isFieldQuestion = preg_match('/\b(which|what|who)\b/i', $message) === 1
            || str_contains($normalized, 'called it')
            || str_contains($normalized, 'is it');

        if (! $isFieldQuestion) {
            return null;
        }

        $fieldMap = [
            'product name' => ['column' => 'product_name', 'label' => 'Product'],
            'cc_email' => ['column' => 'cc_email', 'label' => 'CC Email'],
            'cc email' => ['column' => 'cc_email', 'label' => 'CC Email'],
            'alt_no' => ['column' => 'alt_no', 'label' => 'Alt No'],
            'alt no' => ['column' => 'alt_no', 'label' => 'Alt No'],
            'merchant' => ['column' => 'merchant', 'label' => 'Merchant'],
            'status' => ['column' => 'status', 'label' => 'Status'],
            'product' => ['column' => 'product_name', 'label' => 'Product'],
            'phone' => ['column' => 'phone', 'label' => 'Phone'],
            'city' => ['column' => 'city', 'label' => 'City'],
            'address' => ['column' => 'address', 'label' => 'Address'],
            'code' => ['column' => 'code', 'label' => 'Code'],
        ];

        foreach ($fieldMap as $needle => $field) {
            if (str_contains($normalized, $needle)) {
                return $field;
            }
        }

        return null;
    }

    private function extractStatus(string $message): ?string
    {
        $normalized = mb_strtolower($message);
        $statuses = [
            'delivered',
            'cancelled',
            'returned',
            'expired',
            'pending',
            'confirmed',
            'remitted',
            'processing',
        ];

        foreach ($statuses as $status) {
            if (str_contains($normalized, $status)) {
                return $status;
            }
        }

        return null;
    }

    private function extractSearchTerm(string $message): ?string
    {
        if (preg_match('/\bfor\s+(.+)$/i', trim($message), $matches) === 1) {
            return $this->sanitizeSearchTerm((string) $matches[1]);
        }

        if (preg_match('/\b(client|customer|city|product|product name|merchant|store|agent|status|code|cc email|cc_email|phone|alt no|alt_no|address)\s+(.+)$/i', trim($message), $matches) === 1) {
            return $this->sanitizeSearchTerm((string) $matches[2]);
        }

        return null;
    }

    private function extractFieldValue(string $message, string $label): ?string
    {
        $quotedPattern = '/\b'.preg_quote($label, '/').'\s*[:=]?\s*"([^"]+)"/i';
        if (preg_match($quotedPattern, $message, $matches) === 1) {
            return $this->sanitizeSearchTerm((string) $matches[1]);
        }

        $plainPattern = '/\b'.preg_quote($label, '/').'\s*[:=]?\s+([a-z0-9@._\-\/ ]+?)(?=\s+\b(status|merchant|cc email|cc_email|code|delivery date|order date|product name|product|phone|alt no|alt_no|city|address|orders?|show|find|analyze)\b|$)/i';
        if (preg_match($plainPattern, $message, $matches) === 1) {
            return $this->sanitizeSearchTerm((string) $matches[1]);
        }

        return null;
    }

    /**
     * @return array{string, string}|null
     */
    private function extractDateRange(string $message, string $label): ?array
    {
        $pattern = '/\b'.preg_quote($label, '/').'\s+(\d{4}-\d{2}-\d{2})\s+(?:to|and|-)\s+(\d{4}-\d{2}-\d{2})/i';

        if (preg_match($pattern, $message, $matches) !== 1) {
            return null;
        }

        return [(string) $matches[1], (string) $matches[2]];
    }

    private function extractSingleDate(string $message, string $label): ?string
    {
        $pattern = '/\b'.preg_quote($label, '/').'\s+(\d{4}-\d{2}-\d{2})/i';

        if (preg_match($pattern, $message, $matches) !== 1) {
            return null;
        }

        return (string) $matches[1];
    }

    private function needsDateClarification(string $message): bool
    {
        $normalized = mb_strtolower($message);

        if (! str_contains($normalized, 'date')) {
            return false;
        }

        $isFilterQuery = preg_match('/\b(show|find|list|count|how many|filter)\b/i', $message) === 1;

        if (! $isFilterQuery) {
            return false;
        }

        $mentionsField = str_contains($normalized, 'order date') || str_contains($normalized, 'delivery date');
        $hasDateValue = preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $message) === 1;

        return ! $mentionsField || ! $hasDateValue;
    }

    private function sanitizeSearchTerm(string $value): ?string
    {
        $pattern = '/\b('.implode('|', array_map('preg_quote', $this->searchCommandWords)).')\b/i';
        $clean = trim((string) preg_replace($pattern, '', $value));
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = trim((string) $clean, " \t\n\r\0\x0B.:,;!?");

        return $clean === '' ? null : $clean;
    }

    /**
     * @param  Collection<int, SheetOrder>  $orders
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    private function buildOrdersTablePayload(Collection $orders): array
    {
        $rows = $orders->map(function (SheetOrder $order): array {
            return [
                $this->escapeCell($order->order_no),
                $this->escapeCell($order->status ?: 'Unknown'),
                $this->escapeCell($order->merchant),
                $this->escapeCell($order->code),
                $this->escapeCell($order->order_date?->format('Y-m-d') ?? ''),
                $this->escapeCell($order->delivery_date?->format('Y-m-d') ?? ''),
                $this->escapeCell($order->product_name),
                $this->escapeCell($order->phone ?: $order->alt_no),
                $this->escapeCell($order->city),
            ];
        })->all();

        return [
            'headers' => $this->defaultOrderTableHeaders,
            'rows' => $rows,
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function formatStatusTable(Collection $rows): string
    {
        $lines = $rows->map(function (object $row): string {
            return sprintf(
                '| %s | %s | %s |',
                $this->escapeCell((string) $row->status_label),
                $this->escapeCell((string) $row->total_orders),
                $this->escapeCell($this->formatMoney((float) $row->total_amount)),
            );
        })->all();

        return implode("\n", [
            '| Status | Orders | Amount |',
            '| --- | --- | --- |',
            ...$lines,
        ]);
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2);
    }

    private function escapeCell(?string $value): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return '-';
        }

        return str_replace(['|', "\n", "\r"], ['/', ' ', ' '], $text);
    }

    /**
     * @param  array<string, mixed>|null  $table
     * @return array{
     *   reply: string,
     *   model: string,
     *   intent: string,
     *   fallback_used: bool,
     *   warnings: list<string>,
     *   context: array{boost: bool, retrieval_chunks: int},
     *   meta: array<string, mixed>
     * }
     */
    private function wrapReply(string $reply, ?array $table = null): array
    {
        return [
            'reply' => $reply,
            'model' => 'system:sheet-orders',
            'intent' => 'orders',
            'fallback_used' => false,
            'warnings' => [],
            'context' => [
                'boost' => false,
                'retrieval_chunks' => 0,
            ],
            'meta' => $table !== null ? ['order_table' => $table] : [],
        ];
    }
}
