<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('
            CREATE OR REPLACE VIEW vw_monthly_category_analytics AS
            SELECT 
                t."idUser" AS user_id,
                t."idCategory" AS category_id,
                c.name AS category_name,
                c.type AS category_type,
                EXTRACT(YEAR FROM t.date) AS year,
                EXTRACT(MONTH FROM t.date) AS month,
                SUM(t.amount) AS total_amount,
                COUNT(t."idTransaction") AS transaction_count
            FROM transactions t
            JOIN categories c ON t."idCategory" = c."idCategory"
            GROUP BY 
                t."idUser",
                t."idCategory",
                c.name,
                c.type,
                EXTRACT(YEAR FROM t.date),
                EXTRACT(MONTH FROM t.date)
        ');

        DB::statement("
            CREATE OR REPLACE VIEW vw_monthly_budget_usage AS
            SELECT 
                b.\"idUser\" AS user_id,
                b.\"idCategory\" AS category_id,
                c.name AS category_name,
                v.year,
                v.month,
                b.amount AS budget_limit,
                COALESCE(v.total_amount, 0) AS total_spent,
                (b.amount - COALESCE(v.total_amount, 0)) AS remaining_budget,
                CASE 
                    WHEN COALESCE(v.total_amount, 0) > b.amount THEN 'Overbudget'
                    WHEN COALESCE(v.total_amount, 0) > (b.amount * 0.8) THEN 'Warning'
                    ELSE 'Safe'
                END AS status
            FROM budgets b
            JOIN categories c ON b.\"idCategory\" = c.\"idCategory\"
            LEFT JOIN vw_monthly_category_analytics v ON b.\"idCategory\" = v.category_id AND b.\"idUser\" = v.user_id
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP VIEW IF EXISTS vw_monthly_budget_usage");
        DB::statement("DROP VIEW IF EXISTS vw_monthly_category_analytics");
    }
};
