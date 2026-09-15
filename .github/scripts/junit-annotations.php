<?php

declare(strict_types=1);

/*
 * CI: turn the failures and errors in a JUnit log into GitHub annotations.
 * Job logs can only be downloaded with admin rights, but annotations are
 * public (GET /repos/{owner}/{repo}/check-runs/{job id}/annotations).
 *
 *     php junit-annotations.php <junit.xml>
 */

$log = $argv[1] ?? '';
$xml = is_file($log) ? @simplexml_load_file($log) : false;
if ($xml === false) {
    echo "no readable JUnit log at '$log'\n";
    exit(0);
}

// Workflow-command escaping for the message, and for property values.
$data = static fn (string $s): string => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $s);
$prop = static fn (string $s): string => str_replace([':', ','], ['%3A', '%2C'], $data($s));

$workspace = rtrim(str_replace('\\', '/', (string) getenv('GITHUB_WORKSPACE')), '/') . '/';
$count     = 0;
foreach ($xml->xpath('//testcase') ?: [] as $case) {
    foreach ($case->xpath('failure|error') ?: [] as $problem) {
        // Pest writes "path::test description" into file= and no line; the
        // location is in the message instead ("at tests/FooTest.php:12").
        $file = explode('::', str_replace('\\', '/', (string) $case['file']), 2)[0];
        $line = (int) $case['line'];
        if ($line === 0 && preg_match('~\bat (\S+):(\d+)~', str_replace('\\', '/', (string) $problem), $m)
            && str_ends_with($m[1], basename($file))) {
            $line = (int) $m[2];
        }
        if ($workspace !== '/' && str_starts_with($file, $workspace)) {
            $file = substr($file, strlen($workspace));
        }
        $class = (string) $case['class'] !== '' ? (string) $case['class'] : (string) $case['classname'];

        printf(
            "::error file=%s,line=%d,title=%s::%s\n",
            $prop($file),
            $line,
            $prop($class . ' > ' . $case['name']),
            $data(trim((string) $problem)),
        );
        $count++;
    }
}

echo "$count failure(s) annotated\n";
