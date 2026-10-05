document.addEventListener('DOMContentLoaded', function(){

    if (
        typeof APIPlatformProfileChart ===
        'undefined'
    ){
        return;
    }

    const chartEl =
        document.getElementById('usageChart');

    if (!chartEl) return;

    new Chart(chartEl, {

        type: 'line',

        data: {

            labels: [
                '1','2','3','4','5','6','7'
            ],

            datasets: [{

                label: 'Usage',

                data:
                    APIPlatformProfileChart.data,

                borderWidth: 2,

                fill: false
            }]
        }
    });
});