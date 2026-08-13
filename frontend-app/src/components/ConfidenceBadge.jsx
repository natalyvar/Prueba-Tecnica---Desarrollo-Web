export default function ConfidenceBadge({ score }) {
  if (score === null || score === undefined) {
    return <span className="badge badge-missing">sin dato</span>
  }

  const pct = Math.round(score * 100)
  let level = 'high'
  if (score < 0.6) level = 'low'
  else if (score < 0.9) level = 'mid'

  return (
    <span className={`badge badge-${level}`} title={`Confianza: ${pct}%`}>
      {level === 'low' ? '⚠ ' : ''}
      {pct}%
    </span>
  )
}
