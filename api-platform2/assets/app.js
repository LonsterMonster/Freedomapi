let chart;

async function loadData(){

    const res = await fetch('/wp-json/platform/v1/realtime');
    const data = await res.json();

    const labels = data.map(a => a.name);
    const values = data.map(a => a.usage);

    if(chart){
        chart.data.labels = labels;
        chart.data.datasets[0].data = values;
        chart.update();
    } else {
        chart = new Chart(document.getElementById('chart'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Usage',
                    data: values
                }]
            }
        });
    }
}

setInterval(loadData, 3000);
loadData();