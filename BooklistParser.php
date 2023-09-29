<?php

use Smalot\PdfParser\Parser;

class BooklistParser {
    private Parser $pdfParser;
    const NUM_HEADER_FIELDS = 24;
    const NO_BOOKS_PREFIX = "***";
    const USE_TYPES = ["REQ", "REC", "SUG", "CHC"];
    private int $numPages = 0;
    private int $numRows = 0;
    private int $numBooks = 0;
    private int $pos = 0;
    private int $backTrackPos = 0;
    private int $numItems = 0;
    private array $items = [];
    private array $allCourses = [];
    private array $parsedCourses = [];
    private array $currentCourse = [];
    private string $currentTerm = "";

    public function __construct(Parser $parser) {
        $this->pdfParser = $parser;
    }

    public function parse(string $filename): array {
        $this->log("Started parsing {$filename}");
        $pdf = $this->pdfParser->parseFile($filename);
        foreach ($pdf->getPages() as $page) {
            $this->pos = 0;
            $this->numPages++;
            $this->items = $page->getTextArray();
            $this->numItems = count($this->items);

            while (!$this->isEnd()) {
                if (!$this->currentCourse) {
                    while (!$this->isEnd() && !$this->isCourse()) {
                        $this->moveNext();
                    }
                    if ($this->isEnd()) {
                        break;
                    }

                    $this->startCurrentCourse();

                    if (!$this->isDate()) {
                        $this->parseInstructor();
                    }

                    if (!$this->isDate()) {
                        if (!$this->parseSectionNote()) {
                            break;
                        }
                    }
                    $this->parseStartDate();
                } else {
                    $this->skipNextHeader();
                    // New course?
                    if ($this->isCourse()) {
                        $this->endCurrentCourse();
                        continue;
                    }
                }

                if ($this->isEnd()) {
                    break;
                }

                // Class cancelled or No text required
                if (str_starts_with($item = $this->getItem(), self::NO_BOOKS_PREFIX)) {
                    $this->numRows++;
                    $this->currentCourse['BOOKS'][] = [
                        'ISBN' => $this->items[
                        $item === self::NO_BOOKS_PREFIX ? $this->pos + 1 : $this->pos
                        ]
                    ];
                    $this->endCurrentCourse();
                    continue;
                }

                // Parse all books for the current course
                while (!$this->isEnd() && ($book = $this->parseBook())) {
                    $this->numBooks++;
                    $this->numRows++;
                    $this->currentCourse['BOOKS'][] = $book;
                }

                // New course?
                if (!$this->isEnd() && $this->isCourse()) {
                    $this->endCurrentCourse();
                }
            }

            // Fill the Term for the current page courses
            foreach ($this->parsedCourses as &$parsed_course) {
                $parsed_course['TERM'] = $this->currentTerm;
            }

            $this->allCourses = array_merge($this->allCourses, $this->parsedCourses);
            $this->parsedCourses = [];
            if ($this->numPages % 100 === 0) {
                $cur_cnt = count($this->allCourses);
                $this->log("Processed {$this->numPages} pages ({$cur_cnt} courses)...");
            }
        }

        if ($this->currentCourse) {
            $this->currentCourse['TERM'] = $this->currentTerm;
            $this->allCourses[] = $this->currentCourse;
            $this->currentCourse = [];
        }

        $this->log("Finished parsing {$filename}");
        $this->log(sprintf("Total Pages - %d, Rows - %d, Courses - %d, Books - %d",
                $this->numPages, $this->numRows, count($this->allCourses), $this->numBooks));
        $courses = $this->allCourses;
        $this->allCourses = [];

        return $courses;
    }

    private function skipNextHeader(): void {
        // Skip the header on the next page
        if ($this->pos === 0 &&
            $this->items[$this->pos] === "COURSE" &&
            !empty($this->items[$this->pos + self::NUM_HEADER_FIELDS])) {
            if ($this->items[$this->pos + self::NUM_HEADER_FIELDS - 1] !== "USED") {
                $this->error($this->items[$this->pos + self::NUM_HEADER_FIELDS - 1], "header field");
            }
            $this->pos += self::NUM_HEADER_FIELDS;
        }
    }

    private function startCurrentCourse(): void {
        $struct = explode(' ', $this->peekItem());
        $this->currentCourse = [
            "DEPARTMENT" => $struct[0],
            "COURSE" => $struct[1],
            "SECTION" => $struct[2],
            'BOOKS' => [],
        ];
    }

    private function endCurrentCourse(): void {
        $this->parsedCourses[] = $this->currentCourse;
        $this->currentCourse = [];
    }

    private function parseInstructor(): void {
        if (!preg_match('/^[A-Z]+/', $this->getItem())) {
            $this->error($this->getItem(), "INSTRUCTOR");
        }
        $this->currentCourse["INSTRUCTOR"] = $this->peekItem();
    }

    private function parseSectionNote(): bool {
        $section_note = $this->peekItem(false);
        while (!$this->isEnd() && !$this->isDate()) {
            $section_note .= $this->peekItem(false);
        }
        if ($this->isEnd()) {
            return false;
        }
        $this->currentCourse["SECTION NOTE"] = trim($section_note);
        return true;
    }

    private function parseStartDate(): void {
        if (!$this->isDate()) {
            $this->error($this->getItem(), "CLASS START DATE");
        }
        $this->currentCourse["CLASS START DATE"] = $this->peekItem();
    }

    private function parseBook(): array {
        $this->backTrackPos = $this->pos;

        while (!$this->isEnd() && !$this->isCourse() && !$this->isISBN() && !$this->isPrice()) {
            $this->moveNext();
        }

        if ($this->isEnd() || $this->isCourse()) {
            return [];
        }

        // If there was no ISBN, try to backtrack to DURATION field
        $empty_isbn = false;
        if ($this->isPrice()) {
            $empty_isbn = true;
            while (!$this->isDuration() && $this->pos > $this->backTrackPos) {
                $this->pos--;
            }
            if ($this->pos <= $this->backTrackPos) {
                $this->error($this->getItem(), "ISBN"); // invalid record
            }
        }

        $book['AUTHOR'] = $this->parseAuthor();

        //Try to parse year and edition
        if ($this->isYear($this->items[$this->pos-1])) {
            $book["CY"] = trim($this->items[$this->pos-1]);
            if ($this->isEdition($this->items[$this->pos-2])) {
                $book["ED"] = trim($this->items[$this->pos-2]);
            }
        } elseif ($this->isEdition($this->items[$this->pos-1])) {
            $book["ED"] = trim($this->items[$this->pos-1]);
        }

        $book['TITLE'] = $this->parseTitle($book);

        $book['ISBN'] = !$empty_isbn ? trim($this->peekItem()) : '';

        if (!$this->isDuration()) {
            $this->error($this->getItem(), "DURATION");
        }
        $book['DURATION'] = $this->peekItem();

        $book['PUB'] = $this->peekItem();

        if (strlen($this->getItem()) < 3) {
            $book['PUB'] .= $this->peekItem();
        }

        if (!in_array($this->getItem(), self::USE_TYPES, true)) {
            $this->error($this->getItem(), "USE");
        }

        $book['USE'] = $this->peekItem();

        while (!$this->isEnd() && !$this->isCourse() && !$this->isPrice()) {
            $this->moveNext();
        }

        if ($this->isPrice()) {
            $book['RETAIL NEW'] = $this->peekItem();
        }

        // Skip remaining prices (RETAIL USED, RENTAL FEES)
        while (!$this->isEnd() && $this->isPrice()) {
            $this->moveNext();
        }

        return $book;
    }

    public function parseAuthor(): string {
        if (!preg_match('/^[A-Z]/', $this->items[$this->backTrackPos])) {
            $this->error($this->items[$this->backTrackPos], "AUTHOR");
        }
        // Try to parse the AUTHOR field with some multiline heuristics (does not work in all cases)
        // When it fails (multiple authors) the rest might be parsed into the TITLE field
        $author = $this->items[$this->backTrackPos++];
        while ($this->backTrackPos < $this->pos && (
                strlen($this->items[$this->backTrackPos]) < 3 ||
                str_ends_with($author, '-') ||
                str_ends_with($author, ' '))
        ) {
            $author .= $this->items[$this->backTrackPos++];
        }
        return trim($author);
    }
    public function parseTitle(array $book): string {
        // Try to parse the TITLE field (everything between AUTHOR and (ED or CY or ISBN) is treated as TITLE)
        $title = '';
        while ($this->backTrackPos < $this->pos &&
            trim($this->items[$this->backTrackPos]) !== ($book["ED"] ?? $book["CY"] ?? null)
        ) {
            $title .= $this->items[$this->backTrackPos++];
        }
        return trim($title);
    }

    private function moveNext(): void {
        if ($this->isTerm()) {
            $item = $this->getItem();
            $term = trim(substr($item, 6));
            if ($term !== $this->currentTerm) {
                $this->currentTerm = $term;
            }
        }
        $this->pos++;
    }

    private function peekItem(bool $trim = true) {
        $item = $this->items[$this->pos++];
        return $trim ? trim($item) : $item;
    }

    private function getItem() {
        return trim($this->items[$this->pos]);
    }
    private function isEnd(): bool {
        return $this->pos >= $this->numItems;
    }

    private function isDuration(): bool {
        $item = $this->getItem();
        return in_array($item, ["N/A", "PURCHASE"], true) || preg_match('/\d{2,3} DAYS/', $item);
    }

    private function isYear($item): bool {
        return (bool)preg_match('/\d{4}/', $item);
    }

    private function isEdition($item): bool {
        return (bool)preg_match('/\d{1,2}[A-Z]{2,4}/', $item);
    }

    private function isDate(): bool {
        return (bool)preg_match('/\d{2}\/\d{2}\/\d{4}/', $this->items[$this->pos]);
    }
    private function isCourse(): bool {
        return (bool)preg_match('/[A-Za-z]{2,10} \d{3} \d{2}/', $this->items[$this->pos]);
    }

    private function isPrice(): bool {
        return str_starts_with($this->items[$this->pos], '$ ');
    }

    private function isTerm(): bool {
        return str_starts_with($this->items[$this->pos], 'TERM:');
    }

    private function isISBN(): bool {
        return (bool)preg_match('/97\d{11}/', trim($this->getItem()));
    }

    function error($value, $field) {
        throw new Exception(sprintf(
            "Error on page %d, row %d - invalid %s: %s\n",
            $this->numPages, $this->numRows + 1, $field, $value
        ));
    }

    public static function log(string $message): void {
        echo sprintf("[%s] %s\n", date('Y-m-d H:i:s'), trim($message));
    }
}