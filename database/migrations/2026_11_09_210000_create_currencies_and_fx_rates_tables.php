<?php

use App\Support\Database\TableChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 shared data layer, part 1: the currency list and the FX rate history.
 *
 * currencies is a global reference list (an ISO 4217 subset, with each currency's
 * minor-unit digits), loaded by this migration so it exists wherever the schema does.
 *
 * fx_rates is an append-only history. A rate is an integer, rate_e8 = rate x 10^8
 * (1 USD = 18.4649 ZAR is stored as 1846490000). A row with no owner is a global rate
 * (a published reference rate); a row owned by a ledger is that ledger's own rate and
 * wins over a global one for the same day. A correction is a new row, never an edit.
 */
return new class extends Migration
{
    /** code => [numeric code, name, symbol, minor-unit digits] */
    private const CURRENCIES = [
        'ZAR' => ['710', 'South African rand', 'R', 2],
        'USD' => ['840', 'United States dollar', '$', 2],
        'EUR' => ['978', 'Euro', '€', 2],
        'GBP' => ['826', 'Pound sterling', '£', 2],
        'AUD' => ['036', 'Australian dollar', 'A$', 2],
        'CAD' => ['124', 'Canadian dollar', 'C$', 2],
        'CHF' => ['756', 'Swiss franc', 'CHF', 2],
        'JPY' => ['392', 'Japanese yen', '¥', 0],
        'CNY' => ['156', 'Chinese yuan renminbi', 'CN¥', 2],
        'INR' => ['356', 'Indian rupee', '₹', 2],
        'NZD' => ['554', 'New Zealand dollar', 'NZ$', 2],
        'SGD' => ['702', 'Singapore dollar', 'S$', 2],
        'HKD' => ['344', 'Hong Kong dollar', 'HK$', 2],
        'SEK' => ['752', 'Swedish krona', 'kr', 2],
        'NOK' => ['578', 'Norwegian krone', 'kr', 2],
        'DKK' => ['208', 'Danish krone', 'kr', 2],
        'BRL' => ['986', 'Brazilian real', 'R$', 2],
        'MXN' => ['484', 'Mexican peso', 'MX$', 2],
        'AED' => ['784', 'United Arab Emirates dirham', 'AED', 2],
        'SAR' => ['682', 'Saudi riyal', 'SAR', 2],
        'BWP' => ['072', 'Botswana pula', 'P', 2],
        'NAD' => ['516', 'Namibian dollar', 'N$', 2],
        'LSL' => ['426', 'Lesotho loti', 'L', 2],
        'SZL' => ['748', 'Swazi lilangeni', 'E', 2],
        'MZN' => ['943', 'Mozambican metical', 'MT', 2],
        'ZMW' => ['967', 'Zambian kwacha', 'ZK', 2],
        'KES' => ['404', 'Kenyan shilling', 'KSh', 2],
        'NGN' => ['566', 'Nigerian naira', '₦', 2],
        'GHS' => ['936', 'Ghanaian cedi', 'GH₵', 2],
        'TZS' => ['834', 'Tanzanian shilling', 'TSh', 2],
        'UGX' => ['800', 'Ugandan shilling', 'USh', 0],
        'KWD' => ['414', 'Kuwaiti dinar', 'KWD', 3],
        'BHD' => ['048', 'Bahraini dinar', 'BHD', 3],
        'OMR' => ['512', 'Omani rial', 'OMR', 3],
    ];

    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->char('numeric_code', 3)->nullable();
            $table->string('name', 80);
            $table->string('symbol', 8)->nullable();
            $table->unsignedTinyInteger('minor_units')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        TableChecks::add('currencies', 'minor_units_range', 'minor_units >= 0 AND minor_units <= 4', ['minor_units'], 'currencies: minor_units must be between 0 and 4');
        TableChecks::add('currencies', 'code_format', 'length(code) = 3 AND code = upper(code)', ['code'], 'currencies: code is three upper-case letters');

        $now = now();
        foreach (self::CURRENCIES as $code => [$numeric, $name, $symbol, $digits]) {
            DB::table('currencies')->insertOrIgnore([
                'code' => $code, 'numeric_code' => $numeric, 'name' => $name, 'symbol' => $symbol,
                'minor_units' => $digits, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            // Neither owner set = a global rate; one owner set = that ledger's own rate.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            // 1 unit of from_currency = rate_e8 / 10^8 units of to_currency.
            $table->bigInteger('rate_e8');
            $table->date('as_of');
            $table->string('source', 30);
            $table->timestamp('fetched_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('from_currency')->references('code')->on('currencies');
            $table->foreign('to_currency')->references('code')->on('currencies');
            $table->index(['from_currency', 'to_currency', 'as_of'], 'fx_rates_pair_date_index');
            $table->index(['team_id', 'from_currency', 'to_currency', 'as_of'], 'fx_rates_team_pair_date_index');
            $table->index(['user_id', 'from_currency', 'to_currency', 'as_of'], 'fx_rates_user_pair_date_index');
        });

        TableChecks::ownerOrGlobalRule('fx_rates');
        TableChecks::add('fx_rates', 'rate_positive', 'rate_e8 > 0', ['rate_e8'], 'fx_rates: a rate must be greater than zero');
        TableChecks::add('fx_rates', 'distinct_pair', 'from_currency <> to_currency', ['from_currency', 'to_currency'], 'fx_rates: a rate converts between two different currencies');
    }

    public function down(): void
    {
        TableChecks::drop('fx_rates', 'owner_rule');
        TableChecks::drop('fx_rates', 'rate_positive');
        TableChecks::drop('fx_rates', 'distinct_pair');
        Schema::dropIfExists('fx_rates');

        TableChecks::drop('currencies', 'minor_units_range');
        TableChecks::drop('currencies', 'code_format');
        Schema::dropIfExists('currencies');
    }
};
