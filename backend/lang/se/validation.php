<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines (Swedish)
    |--------------------------------------------------------------------------
    */

    'accepted' => ':attribute måste accepteras.',
    'accepted_if' => ':attribute måste accepteras när :other är :value.',
    'active_url' => ':attribute måste vara en giltig URL.',
    'after' => ':attribute måste vara ett datum efter :date.',
    'after_or_equal' => ':attribute måste vara ett datum som är :date eller senare.',
    'alpha' => ':attribute får bara innehålla bokstäver.',
    'alpha_dash' => ':attribute får bara innehålla bokstäver, siffror, bindestreck och understreck.',
    'alpha_num' => ':attribute får bara innehålla bokstäver och siffror.',
    'array' => ':attribute måste vara en lista.',
    'ascii' => ':attribute får bara innehålla alfanumeriska tecken och symboler i single-byte-format.',
    'before' => ':attribute måste vara ett datum före :date.',
    'before_or_equal' => ':attribute måste vara ett datum som är :date eller tidigare.',
    'between' => [
        'array' => ':attribute måste innehålla mellan :min och :max poster.',
        'file' => ':attribute måste vara mellan :min och :max kilobyte.',
        'numeric' => ':attribute måste vara mellan :min och :max.',
        'string' => ':attribute måste vara mellan :min och :max tecken.',
    ],
    'boolean' => ':attribute måste vara sant eller falskt.',
    'confirmed' => 'Bekräftelsen av :attribute stämmer inte.',
    'current_password' => 'Lösenordet är felaktigt.',
    'date' => ':attribute måste vara ett giltigt datum.',
    'date_equals' => ':attribute måste vara ett datum som är lika med :date.',
    'date_format' => ':attribute måste ha formatet :format.',
    'decimal' => ':attribute måste ha :decimal decimaler.',
    'declined' => ':attribute måste avböjas.',
    'declined_if' => ':attribute måste avböjas när :other är :value.',
    'different' => ':attribute och :other måste vara olika.',
    'digits' => ':attribute måste vara :digits siffror.',
    'digits_between' => ':attribute måste vara mellan :min och :max siffror.',
    'dimensions' => ':attribute har ogiltiga bilddimensioner.',
    'distinct' => ':attribute innehåller ett dubblettvärde.',
    'doesnt_end_with' => ':attribute får inte sluta med något av följande: :values.',
    'doesnt_start_with' => ':attribute får inte börja med något av följande: :values.',
    'email' => ':attribute måste vara en giltig e-postadress.',
    'ends_with' => ':attribute måste sluta med något av följande: :values.',
    'enum' => 'Det valda värdet för :attribute är ogiltigt.',
    'exists' => 'Det valda värdet för :attribute är ogiltigt.',
    'file' => ':attribute måste vara en fil.',
    'filled' => ':attribute måste ha ett värde.',
    'gt' => [
        'array' => ':attribute måste innehålla fler än :value poster.',
        'file' => ':attribute måste vara större än :value kilobyte.',
        'numeric' => ':attribute måste vara större än :value.',
        'string' => ':attribute måste vara längre än :value tecken.',
    ],
    'gte' => [
        'array' => ':attribute måste innehålla minst :value poster.',
        'file' => ':attribute måste vara minst :value kilobyte.',
        'numeric' => ':attribute måste vara minst :value.',
        'string' => ':attribute måste vara minst :value tecken.',
    ],
    'image' => ':attribute måste vara en bild.',
    'in' => 'Det valda värdet för :attribute är ogiltigt.',
    'in_array' => ':attribute måste finnas i :other.',
    'integer' => ':attribute måste vara ett heltal.',
    'ip' => ':attribute måste vara en giltig IP-adress.',
    'ipv4' => ':attribute måste vara en giltig IPv4-adress.',
    'ipv6' => ':attribute måste vara en giltig IPv6-adress.',
    'json' => ':attribute måste vara en giltig JSON-sträng.',
    'lowercase' => ':attribute måste skrivas med små bokstäver.',
    'lt' => [
        'array' => ':attribute måste innehålla färre än :value poster.',
        'file' => ':attribute måste vara mindre än :value kilobyte.',
        'numeric' => ':attribute måste vara mindre än :value.',
        'string' => ':attribute måste vara kortare än :value tecken.',
    ],
    'lte' => [
        'array' => ':attribute får inte innehålla fler än :value poster.',
        'file' => ':attribute får vara högst :value kilobyte.',
        'numeric' => ':attribute får vara högst :value.',
        'string' => ':attribute får vara högst :value tecken.',
    ],
    'mac_address' => ':attribute måste vara en giltig MAC-adress.',
    'max' => [
        'array' => ':attribute får inte innehålla fler än :max poster.',
        'file' => ':attribute får vara högst :max kilobyte.',
        'numeric' => ':attribute får vara högst :max.',
        'string' => ':attribute får vara högst :max tecken.',
    ],
    'max_digits' => ':attribute får innehålla högst :max siffror.',
    'mimes' => ':attribute måste vara en fil av typen: :values.',
    'mimetypes' => ':attribute måste vara en fil av typen: :values.',
    'min' => [
        'array' => ':attribute måste innehålla minst :min poster.',
        'file' => ':attribute måste vara minst :min kilobyte.',
        'numeric' => ':attribute måste vara minst :min.',
        'string' => ':attribute måste vara minst :min tecken.',
    ],
    'min_digits' => ':attribute måste innehålla minst :min siffror.',
    'missing' => ':attribute får inte anges.',
    'missing_if' => ':attribute får inte anges när :other är :value.',
    'missing_unless' => ':attribute får inte anges om inte :other är :value.',
    'missing_with' => ':attribute får inte anges när :values anges.',
    'missing_with_all' => ':attribute får inte anges när :values anges.',
    'multiple_of' => ':attribute måste vara en multipel av :value.',
    'not_in' => 'Det valda värdet för :attribute är ogiltigt.',
    'not_regex' => ':attribute har ett ogiltigt format.',
    'numeric' => ':attribute måste vara ett tal.',
    'password' => [
        'letters' => ':attribute måste innehålla minst en bokstav.',
        'mixed' => ':attribute måste innehålla minst en stor och en liten bokstav.',
        'numbers' => ':attribute måste innehålla minst en siffra.',
        'symbols' => ':attribute måste innehålla minst en symbol.',
        'uncompromised' => 'Det angivna :attribute har förekommit i en dataläcka. Välj ett annat :attribute.',
    ],
    'present' => ':attribute måste anges.',
    'prohibited' => ':attribute får inte anges.',
    'prohibited_if' => ':attribute får inte anges när :other är :value.',
    'prohibited_unless' => ':attribute får inte anges om inte :other finns i :values.',
    'prohibits' => ':attribute gör att :other inte får anges.',
    'regex' => ':attribute har ett ogiltigt format.',
    'required' => ':attribute är obligatoriskt.',
    'required_array_keys' => ':attribute måste innehålla poster för: :values.',
    'required_if' => ':attribute är obligatoriskt när :other är :value.',
    'required_if_accepted' => ':attribute är obligatoriskt när :other har accepterats.',
    'required_unless' => ':attribute är obligatoriskt om inte :other finns i :values.',
    'required_with' => ':attribute är obligatoriskt när :values anges.',
    'required_with_all' => ':attribute är obligatoriskt när :values anges.',
    'required_without' => ':attribute är obligatoriskt när :values inte anges.',
    'required_without_all' => ':attribute är obligatoriskt när inget av :values anges.',
    'same' => ':attribute måste stämma överens med :other.',
    'size' => [
        'array' => ':attribute måste innehålla :size poster.',
        'file' => ':attribute måste vara :size kilobyte.',
        'numeric' => ':attribute måste vara :size.',
        'string' => ':attribute måste vara :size tecken.',
    ],
    'starts_with' => ':attribute måste börja med något av följande: :values.',
    'string' => ':attribute måste vara en textsträng.',
    'timezone' => ':attribute måste vara en giltig tidszon.',
    'unique' => ':attribute används redan.',
    'uploaded' => ':attribute kunde inte laddas upp.',
    'uppercase' => ':attribute måste skrivas med stora bokstäver.',
    'url' => ':attribute måste vara en giltig URL.',
    'ulid' => ':attribute måste vara ett giltigt ULID.',
    'uuid' => ':attribute måste vara ett giltigt UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Readable Swedish names for the fields that buyers and organizers see
    | most often. Anything not listed falls back to the raw field name.
    |
    */

    'attributes' => [
        'email' => 'e-postadressen',
        'email_confirmation' => 'bekräftelsen av e-postadressen',
        'password' => 'lösenordet',
        'password_confirmation' => 'bekräftelsen av lösenordet',
        'current_password' => 'det nuvarande lösenordet',
        'first_name' => 'förnamnet',
        'last_name' => 'efternamnet',
        'name' => 'namnet',
        'phone' => 'telefonnumret',
        'title' => 'titeln',
        'description' => 'beskrivningen',
        'start_date' => 'startdatumet',
        'end_date' => 'slutdatumet',
        'currency' => 'valutan',
        'timezone' => 'tidszonen',
        'locale' => 'språket',
        'address_line_1' => 'adressrad 1',
        'address_line_2' => 'adressrad 2',
        'city' => 'orten',
        'state_or_region' => 'regionen',
        'zip_or_postal_code' => 'postnumret',
        'country' => 'landet',
        'price' => 'priset',
        'quantity' => 'antalet',
        'promo_code' => 'kampanjkoden',
        'order_locale' => 'orderns språk',
        'payer_alias' => 'mobilnumret',
        'swish_number' => 'Swish-numret',
        'amount' => 'beloppet',
        'reason' => 'orsaken',
        'subject' => 'ämnesraden',
        'message' => 'meddelandet',
        'url' => 'adressen',
    ],

];
