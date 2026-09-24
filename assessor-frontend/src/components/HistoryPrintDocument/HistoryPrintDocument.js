import React, { forwardRef } from 'react';
import { Typography } from '@mui/material';
import { Receipt, Layers } from 'lucide-react';
import { formatAppDate } from '../../utils/dateTime';
import './HistoryPrintDocument.css';

// Helper function to sanitize declarant names by removing leading/trailing commas
const sanitizeDeclarant = (name) => {
  if (!name) return '';
  const s = String(name).trim();
  if (!s) return '';
  let out = s.replace(/\s*,\s*/g, ', ').replace(/^,\s*|\s*,\s*$/g, '').trim();
  if (out === ',') out = '';
  return out;
};

// Helper function to sanitize business names by removing leading/trailing commas
const sanitizeBusinessName = (name) => {
  if (!name) return '';
  const s = String(name).trim();
  if (!s) return '';
  let out = s.replace(/\s*,\s*/g, ', ').replace(/^,\s*|\s*,\s*$/g, '').trim();
  if (out === ',') out = '';
  return out;
};

// Adjust a combined declarant name string ("LAST, FIRST MI" or "LAST, FIRST MI.")
// Apply rule: if middle token length === 1 -> ensure trailing dot; if length > 1 -> no dot
const normalizeDeclarantString = (name) => {
  const s = sanitizeDeclarant(name);
  if (!s) return s;

  const parts = s.split(',');
  if (parts.length < 2) return s;

  const last = parts[0].trim();
  const rest = parts.slice(1).join(',').trim();
  if (!rest) return `${last}`;

  const restParts = rest.split(/\s+/);
  const meaningfulParts = restParts.filter((part) => part.length > 0);

  if (meaningfulParts.length === 0) return `${last}`;
  if (meaningfulParts.length === 1) return `${last}, ${rest}`;

  const first = meaningfulParts[meaningfulParts.length - 2];
  const middleRaw = meaningfulParts[meaningfulParts.length - 1];

  const middleNoDots = middleRaw.replace(/\./g, '');
  const middleFormatted = middleNoDots.length === 1 ? `${middleNoDots}.` : middleNoDots;

  const beforeFirst = meaningfulParts.slice(0, -2).join(' ');
  return `${last}, ${beforeFirst ? beforeFirst + ' ' : ''}${first} ${middleFormatted}`.trim();
};

const formatDateOnly = (dateString) => {
  if (!dateString) return '';
  return formatAppDate(dateString, { month: '2-digit', day: '2-digit', year: 'numeric' });
};


const isEffectivityExemptValue = (value) =>
  value === true ||
  value === 1 ||
  value === '1';

// Helper function to format effectivity year for print/history tables according to whole-year / EXEMPT rules
const formatPrintEffectivity = (item) => {
  if (!item) return '—';
  const isExempt =
    isEffectivityExemptValue(item.effectivity_exempt) ||
    /^exempt$/i.test(String(item.effectivity_date ?? '').trim());

  if (isExempt) {
    return 'EXEMPT';
  }

  const raw = String(item.effectivity_date ?? '').trim();
  if (raw === '') {
    return '—';
  }

  return raw;
};

// Format date nicely for receipt docket
const formatDate = (dateString) => {
  if (!dateString) return '—';
  try {
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString;
    return date.toLocaleDateString('en-PH', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  } catch {
    return dateString;
  }
};

// Format day for "Given this X day of Y, Z"
const getDayWithSuffix = (dateString) => {
  try {
    const date = dateString ? new Date(dateString) : new Date();
    const validDate = isNaN(date.getTime()) ? new Date() : date;
    const day = validDate.getDate();
    const month = validDate.toLocaleDateString('en-PH', { month: 'long' });
    const year = validDate.getFullYear();

    const suffix = (d) => {
      if (d > 3 && d < 21) return 'th';
      switch (d % 10) {
        case 1: return 'st';
        case 2: return 'nd';
        case 3: return 'rd';
        default: return 'th';
      }
    };

    return {
      dayWithSuffix: `${day}${suffix(day)}`,
      month,
      year,
    };
  } catch {
    return { dayWithSuffix: '21st', month: 'September', year: 2026 };
  }
};

const toFormalCase = (text) => {
  if (!text) return '';
  const small = new Set(['of', 'and', 'the', 'for', 'in', 'on', 'at', 'a', 'an']);
  const words = String(text).toLowerCase().split(/\s+/);
  return words.map((w, i) => {
    if (!w) return w;
    if (i > 0 && small.has(w)) return w;
    return w.charAt(0).toUpperCase() + w.slice(1);
  }).join(' ');
};

/**
 * Universal printable history document shared by PropertyTable and RequestsTable.
 * 
 * Props:
 * - settings: Object containing municipal settings and fallback signatories
 * - printHistory: Array of tax declaration history records
 * - requestData: Object containing request/receipt/signatory data (if printed from request)
 * - documentType: 'property' | 'request' (optional, automatically inferred if requestData is present)
 * - paperSize: 'a4' | 'letter' | 'legal' | 'auto' (default: 'a4')
 */
const HistoryPrintDocument = forwardRef(({ settings, printHistory, requestData, documentType, paperSize = 'a4' }, ref) => {
  const isRequest = documentType === 'request' || (documentType === undefined && Boolean(requestData && (requestData.id || requestData.receipt_number || requestData.purpose || requestData.purpose_details || requestData.client_name)));

  const rawLogo = (settings && settings.app_logo_url) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.app_logo_url) || '';
  const appLogoUrl = rawLogo ? (rawLogo + (rawLogo.indexOf('?') === -1 ? '?v=' + Date.now() : '&v=' + Date.now())) : '';
  const headerPh = 'Republic of the Philippines';
  const baseProvince = (settings && settings.header_province) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_province) || 'Bukidnon';
  const headerProvince = `Province of ${toFormalCase(baseProvince)}`;
  const baseMunicipality = (settings && settings.header_municipality) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_municipality) || 'KITAOTAO';
  const headerMunicipality = `MUNICIPALITY OF ${baseMunicipality}`;
  const headerOffice = (settings && settings.header_office) || (typeof window !== 'undefined' && window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.header_office) || 'OFFICE OF THE MUNICIPAL ASSESSOR';
  const headerTitle = 'RECORD VERIFICATION DATA FORM';

  // Purpose of Certification resolution:
  // For requests, Purpose must display the specific user-entered purpose_details only (no fallback to short purpose category).
  const purpose = isRequest
    ? (requestData?.purpose_details ? String(requestData.purpose_details).trim() : '—')
    : ((printHistory && printHistory[0] && printHistory[0].purpose) || 'Certification of Assessor\'s Record');

  const clientName = isRequest
    ? (requestData?.client_name ? String(requestData.client_name).trim() : '—')
    : '—';

  // Receipt and Audit data resolution:
  const rawDateIssued = requestData?.date_issued || requestData?.date_requested || (printHistory && printHistory[0] && printHistory[0].created_at) || '';
  const { dayWithSuffix, month, year } = getDayWithSuffix(rawDateIssued);
  const formattedDateIssued = rawDateIssued
    ? (isRequest ? formatDateOnly(rawDateIssued) : formatDate(rawDateIssued))
    : '—';

  const amountPaidNum = requestData?.amount_paid !== undefined && requestData?.amount_paid !== null && requestData?.amount_paid !== ''
    ? Number(requestData.amount_paid)
    : 0;
  const amountPaidFormatted = amountPaidNum.toFixed(2);
  const currencySymbol = (settings && settings.currency_symbol) || '₱';

  const receiptNumber = requestData?.receipt_number || '';
  const placeIssued = requestData?.place_issued || (settings && settings.request_place_issued_default) || 'Kitaotao, Bukidnon';
  const referenceId = requestData?.id
    ? String(requestData.id)
    : (printHistory && printHistory[0] && (printHistory[0].id || printHistory[0].property_id))
      ? String(printHistory[0].id || printHistory[0].property_id)
      : 'CERT-HIST';

  // Signatory 1: Prepared by resolution
  const preparedByName = (requestData && requestData.prepared_by)
    || (printHistory && printHistory[0] && printHistory[0].updated_by_name)
    || '';
  const preparedByTitle = (requestData && requestData.prepared_by_title)
    || (settings && settings.prepared_by_title)
    || 'Assessment Records Staff';

  // Signatory 2: Verifier signatory resolution:
  // If requestData provides an override, use it (request mode); otherwise fallback to printHistory[0] or settings
  const verifierSignatoryFullName = (isRequest && requestData?.verifier_signatory_name)
    || (printHistory && printHistory[0] && printHistory[0].verifier_signatory_name)
    || (settings && settings.verifier_signatory_name)
    || '';

  const verifierSignatoryTitle = (isRequest && requestData?.verifier_signatory_title)
    || (printHistory && printHistory[0] && printHistory[0].verifier_signatory_title)
    || (settings && settings.verifier_signatory_title)
    || 'Local Assessment Operations Officer II / Appraiser';

  // Signatory 3: Municipal assessor signatory resolution:
  const approvalLabel = (requestData && requestData.approval_label)
    || (settings && settings.approval_label)
    || 'Certified correct as to available record/s:';

  const assessorName = (isRequest && requestData?.municipal_assessor_name)
    || (printHistory && printHistory[0] && printHistory[0].municipal_assessor_name)
    || (settings && settings.municipal_assessor_name)
    || '';

  const assessorSuffix = (isRequest && requestData?.municipal_assessor_suffix)
    || (printHistory && printHistory[0] && printHistory[0].municipal_assessor_suffix)
    || (settings && settings.municipal_assessor_suffix)
    || '';

  const assessorTitle = (isRequest && requestData?.municipal_assessor_title)
    || (printHistory && printHistory[0] && printHistory[0].municipal_assessor_title)
    || (settings && settings.municipal_assessor_title)
    || 'MUNICIPAL ASSESSOR';

  const assessorLicense = (isRequest && requestData?.municipal_assessor_license)
    || (printHistory && printHistory[0] && printHistory[0].municipal_assessor_license)
    || (settings && settings.municipal_assessor_license)
    || '';



  const paperClass = `paper-${paperSize || 'a4'}`;

  return (
    <div ref={ref} className={`print-root document-page ${paperClass}`}>
      {/* Subtle watermark circle */}
      {/* <div className="print-watermark" aria-hidden="true">
        <div className="print-watermark-inner">
          OFFICE OF THE MUNICIPAL ASSESSOR
        </div>
      </div> */}

      <div className="print-header">
        <div className="print-header-control-no">
          <span className="print-header-control-label">CONTROL NO.</span>
          <span className="print-header-control-val">{referenceId}</span>
        </div>
        {appLogoUrl ? (
          <img src={appLogoUrl} alt="Logo" className="print-header-logo" onError={(e) => { e.currentTarget.style.display = 'none'; }} />
        ) : null}
        <div className="print-header-ph">{headerPh}</div>
        <div className="print-header-province">{headerProvince}</div>
        <div className="print-header-municipality">{headerMunicipality}</div>
        <div className="print-header-office">{headerOffice}</div>
        <div className="print-header-sub">
          {`Municipal Assessor's Office, ${toFormalCase(baseMunicipality)}, ${toFormalCase(baseProvince)} • Assessor's Archiving System`}
        </div>
      </div>

      <div className="print-title-section">
        <div className="header-title print-header-form-title">{headerTitle}</div>
        {/* {(isRequest && requestData?.purpose) || requestData?.purpose_category || requestData?.document_type ? (
          <div className="print-header-cert-type">
            {`(${String(requestData?.purpose || requestData?.purpose_category || requestData?.document_type).toUpperCase()})`}
          </div>
        ) : null} */}
      </div>

      {/* Property Information Box - 5 Rows */}
      {/* Property Information Box - 5 Rows */}
      <div className="info-card">
        <div className="info-row">
          <div className="info-cell border-right">
            <span className="label">Tax Declaration Number:</span>
            <span className="value font-mono highlight">
              {(printHistory && printHistory[0] && printHistory[0].tax_declaration_number) || ''}
            </span>
          </div>

          <div className="info-cell">
            <span className="label">PIN:</span>
            <span className="value font-mono">
              {(printHistory && printHistory[0] && printHistory[0].pin) || ''}
            </span>
          </div>
        </div>

        <div className="info-row">
          <div className="info-cell border-right">
            <span className="label">OWNER:</span>
            <span className="value">
              {normalizeDeclarantString(printHistory?.[0]?.declarant_name) || ''}
            </span>
          </div>

          <div className="info-cell">
            <span className="label">ADDRESS:</span>
            <span className="value address">
              {(printHistory && printHistory[0] && printHistory[0].address) || ''}
            </span>
          </div>
        </div>

        <div className="info-row">
          <div className="info-cell border-right">
            <span className="label">ADMINISTRATOR/BUSINESS NAME:</span>
            <span className="value admin">
              {sanitizeBusinessName(printHistory?.[0]?.business_name) || ''}
            </span>
          </div>

          <div className="info-cell">
            <span className="label">ASSESSMENT DATE:</span>
            <span className="value assessment">
              {(printHistory && printHistory[0] && printHistory[0].assessment_date) || ''}
            </span>
          </div>
        </div>

        <div className="info-row">
          <div className="info-cell border-right">
            <span className="label">LOCATION:</span>
            <span className="value location">
              {(printHistory && printHistory[0] && printHistory[0].location) || ''}
            </span>
          </div>

          <div className="info-cell">
            <span className="label">KIND OF PROPERTY:</span>
            <span className="value kind">
              {(printHistory && printHistory[0] &&
                (printHistory[0].kind_of_property_name ||
                  printHistory[0].kind_of_property)) || ''}
            </span>
          </div>
        </div>

        <div className="info-row">
          <div className="info-cell border-right">
            <span className="label">EFFECTIVITY DATE:</span>
            <span className="value effectivity">
              {(printHistory && printHistory[0] &&
                formatPrintEffectivity(printHistory[0])) || '—'}
            </span>
          </div>

          <div className="info-cell">
            <span className="label">GEN. CLASS:</span>
            <span className="value genclass">
              {(printHistory && printHistory[0] &&
                (printHistory[0].gen_class_name ||
                  printHistory[0].gen_class)) || ''}
            </span>
          </div>
        </div>
      </div>

      {/* Continuous Chain Section Header */}
      <div className="print-chain-header section-head">
        <div className="print-chain-header-left">
          <Layers className="print-chain-icon" />
          <span>History</span>
        </div>
        <div className="print-chain-header-right">
          {`${printHistory?.length || 0} Recorded Revision(s)`}
        </div>
      </div>

      <table className="history-table" style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed' }}>
        <colgroup>
          <col style={{ width: '14%' }} />
          <col style={{ width: '15%' }} />
          <col style={{ width: '8%' }} />
          <col style={{ width: '9%' }} />
          <col style={{ width: '7%' }} />
          <col style={{ width: '10%' }} />
          <col style={{ width: '9%' }} />
          <col style={{ width: '9%' }} />
          <col style={{ width: '19%' }} />
        </colgroup>
        <thead>
          <tr className="history-table-header-row">
            <th style={{ textAlign: 'center' }}>
              <span className="th-line">Tax Declaration</span>
              <span className="th-line">Number</span>
            </th>
            <th style={{ textAlign: 'center' }}>
              <span className="th-line">Declarant</span>
            </th>
            <th style={{ textAlign: 'center' }}>
              <span className="th-line">Lot</span>
              <span className="th-line">Number</span>
            </th>
            <th style={{ textAlign: 'center' }}>
              <span className="th-line">Survey</span>
              <span className="th-line">Number</span>
            </th>
            <th style={{ textAlign: 'center' }}>
              <span className="th-line">Area</span>
            </th>
            <th style={{ textAlign: 'center' }}>
              <span className="th-line">Title</span>
              <span className="th-line">Number</span>
            </th>
            <th style={{ textAlign: 'center' }}>
              <span className="th-line">Assessed</span>
              <span className="th-line">Value</span>
            </th>
            <th style={{ textAlign: 'center' }}>
              <span className="th-line">Effectivity</span>
            </th>
            <th style={{ textAlign: 'center' }}>
              <span className="th-line">Memoranda</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {(printHistory || []).map((item, index) => {
            const wasConsolidatedInto = (printHistory || []).some(otherItem => {
              if (otherItem.previous_tax_declaration_number && String(otherItem.previous_tax_declaration_number).includes(';')) {
                const prevTds = String(otherItem.previous_tax_declaration_number).split(';').map(td => String(td).trim());
                return prevTds.includes(String(item.tax_declaration_number).trim());
              }
              return false;
            });
            const isConsolidatedTD = item.previous_tax_declaration_number && String(item.previous_tax_declaration_number).includes(';');
            const isConsolidated = wasConsolidatedInto || isConsolidatedTD;

            const d = normalizeDeclarantString(item.declarant_name);
            const b = item.business_name
              ? String(item.business_name).replace(/,\s*/g, ' ')
              : '';

            const isActiveRow = index === 0;

            return (
              <tr key={index} className={isActiveRow ? 'active-row' : undefined}>
                <td style={{ padding: 4, verticalAlign: 'top', wordBreak: 'break-all', overflowWrap: 'anywhere' }}>
                  <div
                    className="history-td-number"
                    style={{
                      color: isConsolidated ? '#ed6c02' : undefined,
                    }}
                  >
                    {item.tax_declaration_number || ''}
                  </div>
                  {item.pin && (
                    <div className="history-pin" style={{
                      color: isConsolidated ? '#ed6c02' : undefined,
                      marginTop: 2
                    }}>
                      {'PIN: ' + item.pin}
                    </div>
                  )}
                </td>
                <td style={{ padding: 4, verticalAlign: 'top' }}>
                  {d && (
                    <div className="history-declarant">
                      {d}
                    </div>
                  )}
                  {b && (
                    <div className="history-business">
                      {b}
                    </div>
                  )}
                </td>
                <td style={{ padding: 4, verticalAlign: 'top' }}>
                  <div className="history-lot-number">{item.lot_number || ''}
                  </div>
                </td>
                <td style={{ padding: 4, verticalAlign: 'top' }}>
                  <div className="history-survey">
                    {item.survey_number || '—'}
                  </div>
                </td>
                <td style={{ padding: 4, verticalAlign: 'top' }}>
                  {(() => {
                    const haRaw = item.area_hectare;
                    const sqmRaw = item.area_sqm;
                    const oldHaRaw = item.area_hectare_old;
                    const numHa = Number(haRaw);
                    const numSqm = Number(sqmRaw);
                    const hasHa = haRaw !== undefined && haRaw !== null && haRaw !== '' && !isNaN(numHa) && numHa > 0;
                    const hasSqm = sqmRaw !== undefined && sqmRaw !== null && sqmRaw !== '' && !isNaN(numSqm) && numSqm > 0;
                    const hasOldHa = oldHaRaw && oldHaRaw !== '';

                    if (!hasHa && !hasSqm && !hasOldHa) return '';

                    let currentArea = '';
                    let unitStr = '';
                    if (hasHa) {
                      const unit = numHa <= 1 ? 'ha' : 'has';
                      currentArea = `${numHa.toFixed(4)}`;
                      unitStr = unit;
                    } else if (hasSqm) {
                      currentArea = `${numSqm.toFixed(2)}`;
                      unitStr = 'sqm';
                    }

                    return (
                      <>
                        <div className="history-area">
                          {currentArea ? `${currentArea}` : (oldHaRaw || '')}
                        </div>
                        <div className='history-area-unit'>
                          {currentArea ? `${unitStr}` : ''}
                        </div>
                        {hasOldHa && currentArea && (
                          <div className="history-area">
                            {`(Old: ${oldHaRaw})`}
                          </div>
                        )}
                      </>
                    );
                  })()}
                </td>
                <td style={{ padding: 4, verticalAlign: 'top', wordBreak: 'break-all', overflowWrap: 'anywhere', hyphens: 'none' }}>
                  <div className="history-title">
                    {item.title_number || ''}
                  </div>
                </td>
                <td style={{ padding: 4, verticalAlign: 'top' }}>
                  {(() => {
                    const currentValue = item.assessed_value;
                    const oldValue = item.assessed_value_old;
                    const hasCurrent = currentValue !== undefined && currentValue !== null;
                    const hasOld = oldValue && oldValue !== '';

                    if (!hasCurrent && !hasOld) {
                      return <div className="history-assessed-value">₱0.00</div>;
                    }

                    let displayValue = '';
                    if (hasCurrent) {
                      displayValue = `₱${Number(currentValue).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                    }

                    return (
                      <div className="history-assessed-value">
                        {hasOld && displayValue ? `${displayValue} ${oldValue}` : (displayValue || oldValue || '₱0.00')}
                      </div>
                    );
                  })()}
                </td>
                <td style={{ padding: 4, verticalAlign: 'top' }}>
                  <div className="history-effectivity">
                    {formatPrintEffectivity(item)}
                  </div>
                </td>
                <td style={{ padding: 4, verticalAlign: 'top', textAlign: 'left' }}>
                  <div className="history-memoranda memoranda-text" style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', overflowWrap: 'anywhere' }}>
                    {item.memoranda || ''}
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>

      {/* Certification, Signatories & Receipt Docket section (Aistudio translation) */}
      <div className="print-signature print-certification-section" style={{ width: '100%' }}>

        {/* Given Statement */}
        {/* <p className="print-given-statement">
          Given this <strong>{dayWithSuffix}</strong> day of <strong>{month}</strong>, <strong>{year}</strong> at the Office of the Municipal Assessor, <strong>{placeIssued || 'Kitaotao, Bukidnon'}</strong>.
        </p> */}

        {/* 3 Official Signatories (Aistudio grid layout) */}
        <div className="print-signatories signatories-grid">
          {/* Signatory 1: Prepared by */}
          <div className="print-signatory">
            <span className="print-signatory-label">Prepared by:</span>
            <div className="print-signatory-line">
              <p className="print-signatory-name">
                {preparedByName || '\u00A0'}
              </p>
            </div>
            <p className="print-signatory-title">
              {preparedByTitle}
            </p>
          </div>

          {/* Signatory 2: Verified and checked by: */}
          <div className="print-signatory">
            <span className="print-signatory-label">Verified and checked by:</span>
            <div className="print-signatory-line">
              <p className="print-signatory-name">
                {(() => {
                  if (!verifierSignatoryFullName) return '\u00A0';
                  const parts = verifierSignatoryFullName.split(',');
                  const mainName = parts[0]?.trim() || '';
                  const suffix = parts.length > 1 ? parts.slice(1).join(',').trim() : '';
                  return (
                    <span>
                      {mainName}
                      {suffix ? <span style={{ fontSize: '9.5px', fontWeight: 400 }}>{`, ${suffix}`}</span> : null}
                    </span>
                  );
                })()}
              </p>
            </div>
            <p className="print-signatory-title">
              {verifierSignatoryTitle}
            </p>
          </div>

          {/* Signatory 3: Approved by or Certified correct */}
          <div className="print-signatory">
            <span className="print-signatory-label">{approvalLabel}</span>
            <div className="print-signatory-line">
              <p className="print-signatory-name">
                {(() => {
                  const base = assessorName || '';
                  if (!base) return '\u00A0';
                  return (
                    <span>
                      {base}
                      {assessorSuffix ? <span style={{ fontSize: '9.5px', fontWeight: 400 }}>{`, ${assessorSuffix}`}</span> : null}
                    </span>
                  );
                })()}
              </p>
            </div>
            <p className="print-signatory-title">
              {assessorTitle}
            </p>
            {assessorLicense && (
              <p className="print-signatory-license">
                {`License No.: ${assessorLicense}`}
              </p>
            )}
          </div>
        </div>

        {/* Official Receipt Particulars / Local Government Audit Trail Box (Request Only) */}
        {isRequest && (
          <div className="print-receipt-docket receipt-box">
            <div className="print-receipt-docket-header">
              <div className="print-receipt-docket-title">
                <Receipt className="print-receipt-icon" />
                <span className="print-receipt-title-main">OFFICIAL RECEIPT PARTICULARS</span>
                {/* <span className="print-receipt-title-sub">(LOCAL GOVERNMENT AUDIT TRAIL)</span> */}
              </div>
              {/* <span className="print-receipt-form-no">Form No. RPT-CERT-2026</span> */}
            </div>

            <div className="print-receipt-grid">
              <div>
                <span className="print-receipt-field-label">Receipt # (O.R. No.):</span>
                <span className="print-receipt-val-blue print-receipt-number">{receiptNumber || '—'}</span>
              </div>

              <div>
                <span className="print-receipt-field-label">Amount Paid:</span>
                <span className="print-receipt-val-amount">{currencySymbol}{amountPaidFormatted}</span>
              </div>

              <div>
                <span className="print-receipt-field-label">Date Issued:</span>
                <span className="print-receipt-val-bold">{formattedDateIssued}</span>
              </div>

              <div className="print-receipt-col-span-2">
                <span className="print-receipt-field-label">Place Issued:</span>
                <span className="print-receipt-val-medium">{placeIssued || 'Kitaotao, Bukidnon'}</span>
              </div>

              <div className="print-receipt-purpose-row">
                <span className="print-receipt-field-label">Requested by:</span>
                <span className="print-receipt-val-purpose">{clientName}</span>
                <span className="print-receipt-field-label">Purpose:</span>
                <span className="print-receipt-val-purpose">{purpose}</span>
              </div>
            </div>

            <div className="print-receipt-footer">
              <span>Doc. Stamp Tax: PAID & AFFIXED</span>
              <span className="print-receipt-seal">★ VALID ONLY WITH OFFICIAL RAISED DRY SEAL ★</span>
              <span>Ref ID: {referenceId}</span>
            </div>
          </div>
        )}

        {/* Bottom security notice */}
        <p className="print-security-notice">
          This document is generated by the Assessor's Archive System. Any alteration or erasure invalidates this document.
        </p>

      </div>
    </div>
  );
});

HistoryPrintDocument.displayName = 'HistoryPrintDocument';

/**
 * Generates dynamic @page print CSS based on paperSize preference.
 * Supported paper sizes:
 * - 'a4' (default): A4 portrait (210mm x 297mm)
 * - 'letter': Letter portrait (8.5in x 11in)
 * - 'legal': Legal portrait (8.5in x 14in)
 * - 'auto': auto fit portrait
 */
export const getHistoryPrintPageStyle = (paperSize = 'a4') => {
  return `
    @page {
      size: auto;
      margin: 8mm 6mm;
    }

    body {
      background: #ffffff !important;
      color: #000000 !important;
      margin: 0 !important;
      padding: 0 !important;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }

    .document-page,
    .print-root {
      max-width: 100% !important;
      width: 100% !important;
      border: none !important;
      box-shadow: none !important;
      padding: 0 !important;
      margin: 0 !important;
      background: #ffffff !important;
      font-family:
        "Plus Jakarta Sans",
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        Roboto,
        "Helvetica Neue",
        Arial,
        sans-serif !important;
    }

    .print-header-form-title {
      font-family: "Plus Jakarta Sans",
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        Roboto,
        "Helvetica Neue",
        Arial,
        sans-serif !important;
      font-size: 20px !important;
      font-weight: 900 !important;
      text-transform: uppercase !important;
      letter-spacing: 0.15em !important;
      color: #020617 !important;
      text-decoration: underline !important;
      text-decoration-thickness: 2px !important;
      text-underline-offset: 4px !important;
    }

    .print-header-control-val,
    .info-cell .value.font-mono,
    .info-val-tdn,
    .info-val-pin,
    .history-table tbody td:nth-child(1),
    .history-td-number,
    .history-pin,
    .history-table tbody td:nth-child(4),
    .history-survey,
    .history-lot-number,
    .history-area,
    .history-table tbody td:nth-child(7),
    .history-assessed-value,
    .history-table tbody td:nth-child(8),
    .history-effectivity,
    .print-receipt-val-blue,
    .print-receipt-number,
    .print-receipt-val-amount,
    .print-receipt-footer {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace !important;
    }

    .history-table tbody td:nth-child(2),
    .history-declarant,
    .history-business,
    .history-table tbody td:nth-child(3),
    .history-area-unit,
    .history-table tbody td:nth-child(6),
    .history-title,
    .badge-status {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
    }

    .history-table tbody td:nth-child(9),
    .history-memoranda,
    .memoranda-text {
      font-family: ui-serif, Georgia, Cambria, "Times New Roman", Times, serif !important;
      font-size: 8px !important;
      color: #1e293b !important;
      line-height: 1.25 !important;
    }

    table {
      table-layout: fixed !important;
      width: 100% !important;
      max-width: 100% !important;
    }

    th {
      word-break: keep-all !important;
      overflow-wrap: normal !important;
      white-space: normal !important;
      hyphens: none !important;
      overflow: hidden !important;
    }

    td {
      word-break: break-word !important;
      overflow-wrap: break-word !important;
    }

    .history-table {
      border-collapse: collapse !important;
      border-spacing: 0 !important;
      border: 2px solid #0f172a !important;
    }

    .history-table thead {
      display: table-header-group !important;
    }

    .history-table tbody {
      display: table-row-group !important;
    }

    .history-table tbody tr {
      break-inside: auto !important;
      page-break-inside: auto !important;
    }

    .history-td-number,
    .history-pin,
    .history-declarant,
    .history-business,
    .history-lot-number,
    .history-survey,
    .history-area,
    .history-area-unit,
    .history-title,
    .history-assessed-value,
    .history-effectivity {
      break-inside: avoid-page !important;
      page-break-inside: avoid !important;
    }

    .history-table th,
    .history-table td {
      box-sizing: border-box !important;
      border: 1px solid #0f172a !important;
      overflow: visible !important;
      word-break: break-word !important;
      overflow-wrap: break-word !important;
    }

    .history-table tbody td {
      -webkit-box-decoration-break: clone !important;
      box-decoration-break: clone !important;
      padding: 6px 4px !important;
      border-right: 1px solid #0f172a !important;
      border-bottom: 1px solid #0f172a !important;
      overflow: visible !important;
    }

    .history-table-header-row th {
      border-bottom: 2px solid #0f172a !important;
    }

    .history-memoranda,
    .memoranda-text {
      break-inside: auto !important;
      page-break-inside: auto !important;
      overflow: visible !important;
    }

    .info-card {
      border: 2px solid #0f172a !important;
    }

    .info-row {
      border-bottom: 1px solid #0f172a !important;
    }

    .info-cell.border-right {
      border-right: 1px solid #0f172a !important;
    }

    .print-signature,
    .signatories-grid,
    .print-receipt-docket,
    .receipt-box {
      page-break-inside: avoid !important;
      break-inside: avoid !important;
    }
  `;
};

// Backwards-compatible default style (A4)
export const HISTORY_PRINT_PAGE_STYLE = getHistoryPrintPageStyle('a4');

export default HistoryPrintDocument;

