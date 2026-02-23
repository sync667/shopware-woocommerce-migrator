<?php

namespace App\Http\Controllers;

use App\Shopware\ShopwareDB;
use Illuminate\Http\Request;

class ShopwareConfigController extends Controller
{
    /**
     * Get available languages from Shopware database
     */
    public function getLanguages(Request $request)
    {
        $validated = $request->validate([
            'db_host' => 'required|string',
            'db_port' => 'required|integer',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'required|string',
            'ssh' => 'nullable|array',
        ]);

        try {
            $db = new ShopwareDB(
                host: $validated['db_host'],
                port: $validated['db_port'],
                database: $validated['db_database'],
                username: $validated['db_username'],
                password: $validated['db_password'],
                sshConfig: $validated['ssh'] ?? null
            );

            // Query available languages
            $languages = $db->select("
                SELECT
                    LOWER(HEX(l.id)) AS id,
                    l.name,
                    locale.code AS locale_code,
                    CASE WHEN l.id = (
                        SELECT id FROM language WHERE name LIKE '%English%' LIMIT 1
                    ) THEN 1 ELSE 0 END AS is_default
                FROM language l
                LEFT JOIN locale ON locale.id = l.locale_id
                ORDER BY is_default DESC, l.name ASC
            ");

            return response()->json([
                'success' => true,
                'languages' => $languages,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get the live version ID from Shopware database
     */
    public function getLiveVersionId(Request $request)
    {
        $validated = $request->validate([
            'db_host' => 'required|string',
            'db_port' => 'required|integer',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'required|string',
            'ssh' => 'nullable|array',
        ]);

        try {
            $db = new ShopwareDB(
                host: $validated['db_host'],
                port: $validated['db_port'],
                database: $validated['db_database'],
                username: $validated['db_username'],
                password: $validated['db_password'],
                sshConfig: $validated['ssh'] ?? null
            );

            // Query live version ID
            $result = $db->select("
                SELECT LOWER(HEX(id)) AS id
                FROM version
                WHERE name = 'live'
                LIMIT 1
            ");

            $liveVersionId = $result[0]['id'] ?? '0fa91ce3e96a4bc2be4bd9ce752c3425';

            return response()->json([
                'success' => true,
                'live_version_id' => $liveVersionId,
            ]);
        } catch (\Exception $e) {
            // Return default if query fails
            return response()->json([
                'success' => true,
                'live_version_id' => '0fa91ce3e96a4bc2be4bd9ce752c3425',
            ]);
        }
    }
}
