<?php

namespace App\Http\Controllers;

use App\Services\ShopwareDB;
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
            $db = new ShopwareDB([
                'db_host' => $validated['db_host'],
                'db_port' => $validated['db_port'],
                'db_database' => $validated['db_database'],
                'db_username' => $validated['db_username'],
                'db_password' => $validated['db_password'],
                'ssh' => $validated['ssh'] ?? null,
            ]);

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

    public function getSalesChannels(Request $request)
    {
        $validated = $request->validate([
            'db_host' => 'required|string',
            'db_port' => 'required|integer',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'required|string',
            'language_id' => 'nullable|string',
            'ssh' => 'nullable|array',
        ]);

        try {
            $db = new ShopwareDB([
                'db_host' => $validated['db_host'],
                'db_port' => $validated['db_port'],
                'db_database' => $validated['db_database'],
                'db_username' => $validated['db_username'],
                'db_password' => $validated['db_password'],
                'language_id' => $validated['language_id'] ?? null,
                'ssh' => $validated['ssh'] ?? null,
            ]);

            $params = [];
            if (! empty($validated['language_id'])) {
                $nameJoin = 'LEFT JOIN sales_channel_translation sct
                    ON sct.sales_channel_id = sc.id
                    AND sct.language_id = UNHEX(?)';
                $params[] = $validated['language_id'];
            } else {
                $nameJoin = 'LEFT JOIN sales_channel_translation sct
                    ON sct.sales_channel_id = sc.id
                    AND sct.language_id = sc.language_id';
            }

            $channels = $db->select("
                SELECT
                    LOWER(HEX(sc.id)) AS id,
                    COALESCE(sct.name, CONCAT('Channel ', LOWER(HEX(sc.id)))) AS name,
                    sc.active,
                    (SELECT COUNT(*) FROM product_visibility pv WHERE pv.sales_channel_id = sc.id) AS visibility_rows
                FROM sales_channel sc
                {$nameJoin}
                ORDER BY sc.active DESC, visibility_rows DESC, name ASC
            ", $params);

            return response()->json([
                'success' => true,
                'sales_channels' => $channels,
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
            $db = new ShopwareDB([
                'db_host' => $validated['db_host'],
                'db_port' => $validated['db_port'],
                'db_database' => $validated['db_database'],
                'db_username' => $validated['db_username'],
                'db_password' => $validated['db_password'],
                'ssh' => $validated['ssh'] ?? null,
            ]);

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
            return response()->json([
                'success' => true,
                'live_version_id' => '0fa91ce3e96a4bc2be4bd9ce752c3425',
            ]);
        }
    }
}
