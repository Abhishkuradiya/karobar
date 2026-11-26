export default function PaginationControls({ page, meta, onChange }) {
  const lastPage = Math.max(1, Number(meta?.last_page || 1));
  const currentPage = Math.min(Math.max(1, Number(page || 1)), lastPage);
  if (lastPage <= 1) return null;
  const pages = [...new Set([1, currentPage - 1, currentPage, currentPage + 1, lastPage].filter((value) => value >= 1 && value <= lastPage))].sort((a, b) => a - b);
  const items = [];
  pages.forEach((value, index) => {
    if (index && value - pages[index - 1] > 1) items.push(<span className="pagination-ellipsis" key={`gap-${value}`}>…</span>);
    items.push(<button type="button" className={`pagination-page${value === currentPage ? ' is-active' : ''}`} aria-current={value === currentPage ? 'page' : undefined} key={value} onClick={() => onChange(value)}>{value}</button>);
  });
  return <nav className="pagination-controls" aria-label="Pagination"><span className="pagination-count">{meta?.total || 0} records</span><div className="pagination-buttons"><button type="button" className="pagination-arrow" disabled={currentPage <= 1} onClick={() => onChange(currentPage - 1)} aria-label="Previous page"><i className="bx bx-chevron-left" /></button>{items}<button type="button" className="pagination-arrow" disabled={currentPage >= lastPage} onClick={() => onChange(currentPage + 1)} aria-label="Next page"><i className="bx bx-chevron-right" /></button></div></nav>;
}
