#!/usr/bin/env python3
"""Safely patch the ETRACS assessment import block with write diagnostics.

Run this script from the root of the toryang2/assessor repository.
It creates a timestamped backup, replaces only Section 6, and refuses to
edit the file if the expected markers are missing or ambiguous.
"""
from __future__ import annotations

import shutil
import subprocess
import sys
from datetime import datetime
from pathlib import Path

RELATIVE_FILE = Path(
    "assessor-backend/wp-content/plugins/assessor-api/includes/class-assessor-etracs-sync.php"
)
START_MARKER = "        // 6. Sync rpu_assessment with JOINs to get classname and actualuse\n"
END_MARKER = "        update_option('assessor_etracs_last_sync', current_time('mysql', true));\n"

REPLACEMENT = r'''        // 6. Sync rpu_assessment with write diagnostics.
        // Diagnostic only: test in staging before running any synchronization.
        $wp_rpu_assessment = $wpdb->prefix . 'assessor_rpu_assessment';

        $assessment_diag = array(
            'status'                => 'running',
            'rows_seen'             => 0,
            'rows_seen_by_type'     => array(),
            'writes_ok'             => 0,
            'writes_ok_by_type'     => array(),
            'writes_failed'         => 0,
            'failures_by_type'      => array(),
            'first_failure_by_type' => array(),
            'exception'             => null,
        );

        try {
            $valid_columns_ra = $wpdb->get_col(
                "DESCRIBE `$wp_rpu_assessment`",
                0
            );

            $valid_columns_ra_flip = !empty($valid_columns_ra)
                ? array_flip($valid_columns_ra)
                : array();

            if (empty($valid_columns_ra_flip)) {
                throw new RuntimeException(
                    'Could not read columns from target table: '
                    . $wp_rpu_assessment
                );
            }

            $stmt = $pdo->query("
                SELECT
                    ra.*,
                    pc1.code AS classcode,
                    pc1.name AS classname,
                    COALESCE(
                        la.name,
                        ba.name,
                        ma.name,
                        pa.name,
                        mia.name,
                        pc2.name
                    ) AS actualuse
                FROM rpu_assessment ra
                LEFT JOIN propertyclassification pc1
                    ON pc1.objid = ra.classification_objid
                LEFT JOIN propertyclassification pc2
                    ON pc2.objid = ra.actualuse_objid
                LEFT JOIN landassesslevel la
                    ON la.objid = ra.actualuse_objid
                LEFT JOIN bldgassesslevel ba
                    ON ba.objid = ra.actualuse_objid
                LEFT JOIN machassesslevel ma
                    ON ma.objid = ra.actualuse_objid
                LEFT JOIN planttreeassesslevel pa
                    ON pa.objid = ra.actualuse_objid
                LEFT JOIN miscassesslevel mia
                    ON mia.objid = ra.actualuse_objid
            ");

            $stats['rpu_assessment'] = 0;
            self::update_progress(
                'Syncing rpu_assessment with write diagnostics...'
            );

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $assessment_diag['rows_seen']++;

                $type = strtolower(
                    trim((string) ($row['rputype'] ?? '(null)'))
                );

                if (!isset($assessment_diag['rows_seen_by_type'][$type])) {
                    $assessment_diag['rows_seen_by_type'][$type] = 0;
                }
                $assessment_diag['rows_seen_by_type'][$type]++;

                $filtered_row = array_intersect_key(
                    $row,
                    $valid_columns_ra_flip
                );

                if (isset($valid_columns_ra_flip['origin_type'])) {
                    $filtered_row['origin_type'] = 'REPLICA';
                }

                $write_result = self::safe_upsert_row(
                    $wp_rpu_assessment,
                    $filtered_row,
                    'objid'
                );

                if ($write_result === false) {
                    $assessment_diag['writes_failed']++;

                    if (!isset($assessment_diag['failures_by_type'][$type])) {
                        $assessment_diag['failures_by_type'][$type] = 0;
                    }
                    $assessment_diag['failures_by_type'][$type]++;

                    // Keep the first failure per type to limit log volume.
                    if (!isset(
                        $assessment_diag['first_failure_by_type'][$type]
                    )) {
                        $failure = array(
                            'objid' => $row['objid'] ?? null,
                            'rputype' => $type,
                            'row_has_objid' => isset($filtered_row['objid']),
                            'filtered_column_count' => count($filtered_row),
                            'db_error' => $wpdb->last_error !== ''
                                ? $wpdb->last_error
                                : 'Write returned false without a DB error message',
                        );

                        $assessment_diag['first_failure_by_type'][$type]
                            = $failure;

                        error_log(
                            'ETRACS rpu_assessment write failure: '
                            . wp_json_encode($failure)
                        );
                    }
                } else {
                    // A result of 0 can still mean a successful unchanged row.
                    $assessment_diag['writes_ok']++;

                    if (!isset($assessment_diag['writes_ok_by_type'][$type])) {
                        $assessment_diag['writes_ok_by_type'][$type] = 0;
                    }
                    $assessment_diag['writes_ok_by_type'][$type]++;
                }

                // Processed count is not the same as successfully written count.
                $stats['rpu_assessment']++;

                if ($stats['rpu_assessment'] % 500 === 0) {
                    self::update_progress(
                        'Processed rpu_assessment: '
                        . $stats['rpu_assessment']
                        . ' rows; failed writes: '
                        . $assessment_diag['writes_failed']
                    );
                }
            }

            $assessment_diag['status'] =
                $assessment_diag['writes_failed'] > 0
                    ? 'completed_with_write_failures'
                    : 'completed_no_reported_write_failures';

        } catch (Throwable $e) {
            $assessment_diag['status'] = 'exception';
            $assessment_diag['exception'] = array(
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'rows_seen' => $assessment_diag['rows_seen'],
            );

            error_log(
                'ETRACS rpu_assessment import exception: '
                . wp_json_encode($assessment_diag['exception'])
            );
        }

        $assessment_diag['timestamp'] = current_time('mysql', true);
        $assessment_diag['processed_counter'] =
            $stats['rpu_assessment'] ?? 0;

        update_option(
            'assessor_etracs_assessment_write_diag',
            $assessment_diag,
            false
        );

'''


def main() -> int:
    repo_root = Path.cwd()
    target = repo_root / RELATIVE_FILE

    if not target.is_file():
        print(
            "ERROR: Target file not found. Run this script from the repository root:\n"
            f"  Expected: {RELATIVE_FILE.as_posix()}",
            file=sys.stderr,
        )
        return 2

    original = target.read_text(encoding="utf-8")

    if "assessor_etracs_assessment_write_diag" in original:
        print(
            "ERROR: Diagnostics appear to have already been added. No changes made.",
            file=sys.stderr,
        )
        return 3

    if original.count(START_MARKER) != 1:
        print(
            "ERROR: Expected the Section 6 start marker exactly once. No changes made.\n"
            "Your local file may differ from the repository version; inspect it first.",
            file=sys.stderr,
        )
        return 4

    start = original.index(START_MARKER)
    end = original.find(END_MARKER, start)
    if end < 0:
        print(
            "ERROR: Could not find the end marker after Section 6. No changes made.",
            file=sys.stderr,
        )
        return 5

    # Include no content after Section 6: retain the original end marker and rest.
    updated = original[:start] + REPLACEMENT + original[end:]

    stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    backup = target.with_name(target.name + f".bak-{stamp}")
    shutil.copy2(target, backup)
    # Path.write_text() has no newline parameter on older Python versions.
    # Use open(..., newline="") for compatibility and predictable line endings.
    with target.open("w", encoding="utf-8", newline="") as handle:
        handle.write(updated)

    php = shutil.which("php")
    if php:
        lint = subprocess.run(
            [php, "-l", str(target)],
            text=True,
            capture_output=True,
            check=False,
        )
        if lint.returncode != 0:
            shutil.copy2(backup, target)
            print("ERROR: PHP lint failed; original file restored from backup.", file=sys.stderr)
            print(lint.stdout, file=sys.stderr)
            print(lint.stderr, file=sys.stderr)
            return 6
        print(lint.stdout.strip())
    else:
        print("NOTE: PHP CLI not found; PHP syntax lint was skipped.")

    print("Patch applied successfully.")
    print(f"Updated: {target}")
    print(f"Backup:  {backup}")
    print("Diagnostic option: assessor_etracs_assessment_write_diag")
    print("No database sync was run by this script.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
