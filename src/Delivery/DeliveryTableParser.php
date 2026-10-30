<?php

namespace App\Delivery;

/**
 * Extrait le tableau des livraisons d'une page Confluence (format "storage" XHTML).
 *
 * Le tableau retenu est le premier dont l'en-tête contient une colonne "date" et une colonne "version"
 * (libellés paramétrables, comparaison sans casse ni accents, "contient"). Les cellules fusionnées
 * (rowspan / colspan) sont gérées : une date fusionnée sur plusieurs lignes s'applique à chacune.
 */
final class DeliveryTableParser
{
    /**
     * @return array{rows: DeliveryRow[], headers: string[], error: ?string, tables: int}
     */
    public function parse(string $storage, string $dateHeader = 'Date', string $versionHeader = 'Version'): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>'.$storage.'</div>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $tables = $doc->getElementsByTagName('table');
        $headersSeen = [];
        foreach ($tables as $table) {
            $grid = $this->grid($table);
            if (\count($grid) < 1) {
                continue;
            }
            $headers = array_map(fn (\DOMElement $cell) => $this->text($cell), $grid[0]);
            $headersSeen[] = $headers;
            $dateIndex = $this->findColumn($headers, $dateHeader);
            $versionIndex = $this->findColumn($headers, $versionHeader);
            if (null === $dateIndex || null === $versionIndex) {
                continue;
            }

            $rows = [];
            foreach (\array_slice($grid, 1) as $i => $cells) {
                $dateCell = $cells[$dateIndex] ?? null;
                $versionCell = $cells[$versionIndex] ?? null;
                $rawDate = $dateCell ? $this->text($dateCell) : '';
                $lines = $versionCell ? $this->lines($versionCell) : [];
                if ('' === $rawDate && [] === $lines) {
                    continue;
                }
                $others = [];
                foreach ($headers as $index => $header) {
                    if ($index !== $dateIndex && $index !== $versionIndex && isset($cells[$index]) && '' !== $header) {
                        $others[$header] = $this->text($cells[$index]);
                    }
                }
                $rows[] = new DeliveryRow(
                    $i + 1,
                    $dateCell ? $this->date($dateCell) : null,
                    $rawDate,
                    $lines,
                    $others,
                    VersionExtractor::extract(implode("\n", $lines)),
                    $versionCell ? $this->versionIds($versionCell) : [],
                );
            }

            return ['rows' => $rows, 'headers' => $headers, 'error' => null, 'tables' => $tables->length];
        }

        $seen = array_map(fn (array $h) => '« '.implode(' | ', $h).' »', $headersSeen);

        return [
            'rows' => [],
            'headers' => [],
            'tables' => $tables->length,
            'error' => 0 === $tables->length
                ? 'Aucun tableau trouvé dans la page.'
                : sprintf('Aucun tableau avec des colonnes contenant « %s » et « %s ». En-têtes trouvés : %s', $dateHeader, $versionHeader, implode(' ; ', $seen)),
        ];
    }

    /**
     * Tableau à deux dimensions des cellules, cellules fusionnées dupliquées.
     *
     * @return array<int, array<int, \DOMElement>>
     */
    private function grid(\DOMElement $table): array
    {
        $grid = [];
        $carry = []; // colonne => [lignes restantes, cellule]
        $rowIndex = 0;
        foreach ($table->getElementsByTagName('tr') as $tr) {
            // Ignore les lignes des tableaux imbriqués
            if ($this->closestTable($tr) !== $table) {
                continue;
            }
            $row = [];
            $col = 0;
            $cells = array_values(array_filter(iterator_to_array($tr->childNodes), fn ($n) => $n instanceof \DOMElement && \in_array($n->nodeName, ['td', 'th'], true)));
            foreach ($cells as $cell) {
                while (isset($carry[$col])) {
                    $row[$col] = $carry[$col][1];
                    $carry[$col][0]--;
                    if ($carry[$col][0] <= 0) {
                        unset($carry[$col]);
                    }
                    ++$col;
                }
                $colspan = max(1, (int) $cell->getAttribute('colspan'));
                $rowspan = max(1, (int) $cell->getAttribute('rowspan'));
                for ($c = 0; $c < $colspan; ++$c) {
                    $row[$col] = $cell;
                    if ($rowspan > 1) {
                        $carry[$col] = [$rowspan - 1, $cell];
                    }
                    ++$col;
                }
            }
            // Cellules fusionnées en fin de ligne
            foreach ($carry as $c => $item) {
                if (!isset($row[$c]) && $c >= $col) {
                    $row[$c] = $item[1];
                    $carry[$c][0]--;
                    if ($carry[$c][0] <= 0) {
                        unset($carry[$c]);
                    }
                }
            }
            ksort($row);
            $grid[$rowIndex++] = $row;
        }

        return $grid;
    }

    private function closestTable(\DOMNode $node): ?\DOMElement
    {
        for ($n = $node->parentNode; $n; $n = $n->parentNode) {
            if ($n instanceof \DOMElement && 'table' === $n->nodeName) {
                return $n;
            }
        }

        return null;
    }

    /**
     * Colonne dont l'en-tête correspond au libellé : identique en priorité, puis qui commence par le libellé,
     * puis qui le contient (« Version » plutôt que « Leader de Version » ou « PV de version »).
     */
    private function findColumn(array $headers, string $label): ?int
    {
        $label = self::fold($label);
        if ('' === $label) {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach ($headers as $index => $header) {
            $header = self::fold($header);
            $score = match (true) {
                $header === $label => 3,
                str_starts_with($header, $label) => 2,
                str_contains($header, $label) => 1,
                default => 0,
            };
            if ($score > $bestScore) {
                [$best, $bestScore] = [$index, $score];
            }
        }

        return $best;
    }

    /**
     * Identifiants des versions Jira liées dans la cellule (liens vers …/versions/12345, fixforversion/12345…),
     * avec le texte du lien.
     *
     * @return array<string, string> identifiant => texte du lien
     */
    private function versionIds(\DOMElement $cell): array
    {
        $ids = [];
        foreach ($cell->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            if (preg_match('#(?:/versions/|fixforversion/|[?&]version=|[?&]versionId=)(\d+)#i', $href, $m)) {
                $ids[$m[1]] = trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '');
            }
        }

        return $ids;
    }

    private function date(\DOMElement $cell): ?\DateTimeImmutable
    {
        // Macro date de Confluence : <time datetime="2026-03-12" />
        foreach ($cell->getElementsByTagName('time') as $time) {
            if ($date = DateParser::parse($time->getAttribute('datetime'))) {
                return $date;
            }
        }

        return DateParser::parse($this->text($cell));
    }

    private function text(\DOMElement $cell): string
    {
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $this->lines($cell))) ?? '');
    }

    /**
     * Texte de la cellule découpé en lignes (retours à la ligne, paragraphes, éléments de liste).
     *
     * @return string[]
     */
    private function lines(\DOMElement $cell): array
    {
        $text = '';
        $walk = function (\DOMNode $node) use (&$walk, &$text) {
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMText) {
                    $text .= $child->nodeValue;
                } elseif ($child instanceof \DOMElement) {
                    if ('time' === $child->nodeName && '' === trim($child->textContent)) {
                        $text .= $child->getAttribute('datetime');
                    }
                    $block = \in_array($child->nodeName, ['p', 'div', 'li', 'br', 'tr', 'h1', 'h2', 'h3', 'h4'], true);
                    if ($block) {
                        $text .= "\n";
                    }
                    $walk($child);
                    if ($block) {
                        $text .= "\n";
                    }
                }
            }
        };
        $walk($cell);

        $lines = array_map(fn ($l) => trim(preg_replace('/[ \t\x{a0}]+/u', ' ', $l) ?? ''), explode("\n", $text));

        return array_values(array_filter($lines, fn ($l) => '' !== $l));
    }

    /** Minuscules sans accents, pour comparer des libellés */
    public static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ô' => 'o', 'î' => 'i', 'ï' => 'i', 'û' => 'u', 'ù' => 'u', 'ç' => 'c']);
    }
}
