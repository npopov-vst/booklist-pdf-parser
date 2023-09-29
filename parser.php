<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/BooklistParser.php';

if ($argc < 2 || !is_file($argv[1])) {
    die("Usage: php {$argv[0]} input.pdf [output.csv]\n");
}

$pdf_filename = $argv[1];
$csv_filename = $argv[2] ?? basename($argv[1],'.pdf') . '.csv';

try {
    $booklistParser = new BooklistParser(
        new Smalot\PdfParser\Parser()
    );
    $courses = $booklistParser->parse($pdf_filename);

    if (!$courses) {
        $booklistParser::log("Nothing to save");
        die;
    }

    $out = fopen($csv_filename, 'w+');
    if (!$out) {
        throw new Exception("Unable to open {$csv_filename}\n");
    }

    $num_rows = 0;
    $csv_header = [
        'TERM', 'CAMPUS', 'DEPARTMENT', 'COURSE', 'UNDERGRAD', 'SECTION', 'COURSE ID',
        'ENROLLMENT', 'ISBN', 'AUTHOR', 'TITLE', 'PUBLISHER', 'BOOK STATUS', 'NEW RETAIL',
    ];
    fputcsv($out, $csv_header);
    foreach ($courses as $course) {
        foreach ($course['BOOKS'] as $book) {
            $num_rows++;
            fputcsv($out, [
                $course['TERM'],
                "N/A", // CAMPUS
                $course['DEPARTMENT'],
                $course['COURSE'],
                "N/A", // UNDERGRAD
                $course["SECTION"],
                "N/A", // COURSE ID
                "25", // ENROLLMENT
                $book['ISBN'],
                $book['AUTHOR'] ?? "",
                $book['TITLE'] ?? "",
                $book["PUB"] ?? "",
                !empty($book["USE"]) ? ($book["USE"] === "REQ" ? "REQUIRED" : "OPTIONAL") : "",
                $book["RETAIL NEW"] ?? "",
            ]);
        }
    }
    BooklistParser::log("Saved {$num_rows} rows to {$csv_filename}");
} catch (\Exception $e) {
    die($e->getMessage());
}
