function exportTable(tableId, filename) {
  const table = document.getElementById(tableId);
  if (!table) return;
  const rows = [...table.querySelectorAll('tr')].map(row => [...row.querySelectorAll('th,td')].map(cell => {
    const value = cell.innerText.replace(/\s+/g, ' ').trim();
    return '"' + value.replace(/"/g, '""') + '"';
  }).join(','));
  const blob = new Blob(['\ufeff' + rows.join('\r\n')], {type: 'text/csv;charset=utf-8;'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
}
