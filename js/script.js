// Template download function
function downloadTemplate() {
    const template = `Name,Phone,Occupation,Amount to Pay
John Doe,1234567890,Engineer,500.00
Jane Smith,0987654321,Teacher,300.00
Michael Brown,5551234567,Doctor,700.00
Sarah Johnson,4449876543,Lawyer,600.00`;

    const blob = new Blob([template], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'customer_template.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

function downloadPaymentTemplate() {
    const template = `Name,Phone,Occupation,Paid Amount
John Doe,1234567890,Engineer,500.00
Jane Smith,0987654321,Teacher,300.00
Michael Brown,5551234567,Doctor,700.00
Sarah Johnson,4449876543,Lawyer,600.00`;

    const blob = new Blob([template], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'payment_template.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Form validation
document.addEventListener('DOMContentLoaded', function() {
    // Set current month as default for payment import
    const monthInput = document.getElementById('month_year');
    if (monthInput) {
        const now = new Date();
        monthInput.value = now.toISOString().slice(0, 7);
    }

    // File input validation
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(input => {
        input.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const validExtensions = ['.xls', '.xlsx', '.csv'];
                const fileName = file.name.toLowerCase();
                const isValid = validExtensions.some(ext => fileName.endsWith(ext));
                
                if (!isValid) {
                    alert('Please upload only Excel or CSV files.');
                    e.target.value = '';
                }
            }
        });
    });

    // Auto-complete for sector name
    const sectorInput = document.getElementById('sector_name');
    if (sectorInput) {
        sectorInput.addEventListener('input', function() {
            const datalist = document.getElementById('existing_sectors');
            const options = datalist.querySelectorAll('option');
            
            options.forEach(option => {
                if (option.value.toLowerCase().includes(this.value.toLowerCase())) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                }
            });
        });
    }
});

// Reports filtering
function filterReports() {
    const sectorId = document.getElementById('filter_sector').value;
    const customerName = document.getElementById('filter_customer').value.toLowerCase();
    const rows = document.querySelectorAll('.report-table tbody tr');
    
    rows.forEach(row => {
        const sector = row.cells[5]?.textContent || '';
        const customer = row.cells[1]?.textContent.toLowerCase() || '';
        
        let show = true;
        
        if (sectorId && sector !== sectorId) {
            show = false;
        }
        
        if (customerName && !customer.includes(customerName)) {
            show = false;
        }
        
        row.style.display = show ? '' : 'none';
    });
}

// Export reports to CSV
function exportToCSV() {
    const rows = document.querySelectorAll('.data-table tr');
    let csvContent = "data:text/csv;charset=utf-8,";
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('th, td');
        const rowArray = Array.from(cols).map(col => `"${col.textContent.trim()}"`);
        csvContent += rowArray.join(",") + "\r\n";
    });
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "payment_report.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}