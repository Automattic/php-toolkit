<?php

class ConsoleTable {
    private $headers;
    private $rows;
    private $columnWidths;

    public function __construct(array $headers = []) {
        $this->headers = $headers;
        $this->rows = [];
        $this->columnWidths = array_map('strlen', $headers);
    }

    public function addRow(array $row) {
        $this->rows[] = $row;
        foreach ($row as $i => $cell) {
            $width = strlen($cell);
            if (!isset($this->columnWidths[$i]) || $width > $this->columnWidths[$i]) {
                $this->columnWidths[$i] = $width;
            }
        }
    }

    public function render(): string {
        $output = '';
        
        // Print headers
        if (!empty($this->headers)) {
            $output .= $this->renderRow($this->headers);
            $output .= $this->renderSeparator();
        }

        // Print rows
        foreach ($this->rows as $row) {
            $output .= $this->renderRow($row);
        }

        return $output;
    }

    private function renderRow(array $row): string {
        $cells = [];
        foreach ($row as $i => $cell) {
            $cells[] = str_pad($cell, $this->columnWidths[$i]);
        }
        return implode(' | ', $cells) . "\n";
    }

    private function renderSeparator(): string {
        $parts = [];
        foreach ($this->columnWidths as $width) {
            $parts[] = str_repeat('-', $width);
        }
        return implode('-+-', $parts) . "\n";
    }
}
