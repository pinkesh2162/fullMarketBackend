<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Charts when "period" is all
    |--------------------------------------------------------------------------
    |
    | Full-history charts are expensive. For period=all, only the most recent
    | N days are returned for time-series (user growth, DAU, posts per day).
    |
    */
    'all_period_chart_days' => (int) env('ADMIN_DASHBOARD_ALL_CHART_DAYS', 90),

];
