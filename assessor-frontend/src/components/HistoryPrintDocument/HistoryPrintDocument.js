import React, { forwardRef } from 'react';
import { Typography } from '@mui/material';
import { Receipt } from 'lucide-react';
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
 */
const HistoryPrintDocument = forwardRef(({ settings, printHistory, requestData, documentType }, ref) => {
  const isRequest = documentType === 'request' || (documentType === undefined && Boolean(requestData && (requestData.id || requestData.receipt_number || requestData.purpose)));

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

  return (
    <div ref={ref} className="print-root" style={{ width: '210mm' }}>
      <div className="print-header" style={{ textAlign: 'center', fontFamily: 'Times New Roman, sans-serif' }}>
        {appLogoUrl ? (
          <img src={appLogoUrl} alt="Logo" style={{ height: 64, display: 'block', margin: '5mm auto 8px auto' }} onError={(e) => { e.currentTarget.style.display = 'none'; }} />
        ) : null}
        <h4 style={{ fontSize: 16, margin: '-7px 0', fontWeight: 400 }}>{headerPh}</h4>
        <h4 style={{ fontSize: 16, margin: '-7px 0', fontWeight: 400 }}>{headerProvince}</h4>
        <h4 style={{ fontSize: 16, margin: '-7px 0', fontWeight: 400 }}>{headerMunicipality}</h4>
        <h3 style={{ fontSize: 16, margin: '-7px 0', fontWeight: 600 }}>{headerOffice}</h3>
        <div className="header-title" style={{ fontSize: 14, marginTop: 8, fontWeight: 700, textDecoration: 'underline', fontFamily: 'Tahoma, serif' }}>{headerTitle}</div>
      </div>

      <table style={{ border: '1px solid #000', borderCollapse: 'separate', borderSpacing: 0, margin: '12px auto', width: '100%', tableLayout: 'fixed' }} className="info">
        <colgroup>
          <col style={{ width: '50%' }} />
          <col style={{ width: '50%' }} />
        </colgroup>
        <tbody>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top', wordBreak: 'break-all', overflowWrap: 'anywhere' }}>
              <strong>TAX DECLARATION NUMBER:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].tax_declaration_number) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top', wordBreak: 'break-all', overflowWrap: 'anywhere' }}>
              <strong>PIN:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].pin) || ''}</span>
            </td>
          </tr>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>OWNER:</strong> <span>{normalizeDeclarantString(printHistory?.[0]?.declarant_name) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>ADDRESS:</strong>{' '}
              <span style={{ whiteSpace: 'normal', wordBreak: 'break-word', overflowWrap: 'anywhere' }}>
                {(printHistory && printHistory[0] && printHistory[0].address) || ''}
              </span>
            </td>
          </tr>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>ADMINISTRATOR/BUSINESS NAME:</strong> <span>{sanitizeBusinessName(printHistory?.[0]?.business_name) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>ASSESSMENT DATE:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].assessment_date) || ''}</span>
            </td>
          </tr>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>LOCATION:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].location) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>KIND OF PROPERTY:</strong> <span>{(printHistory && printHistory[0] && (printHistory[0].kind_of_property_name || printHistory[0].kind_of_property)) || ''}</span>
            </td>
          </tr>
          <tr>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>EFFECTIVITY DATE:</strong> <span>{(printHistory && printHistory[0] && printHistory[0].effectivity_date) || ''}</span>
            </td>
            <td style={{ border: 'none', padding: '2px 8px', fontSize: 12, verticalAlign: 'top' }}>
              <strong>GEN. CLASS:</strong> <span>{(printHistory && printHistory[0] && (printHistory[0].gen_class_name || printHistory[0].gen_class)) || ''}</span>
            </td>
          </tr>
        </tbody>
      </table>

      <table className="history-table" style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed' }}>
        <colgroup>
          <col style={{ width: '17%' }} />
          <col style={{ width: '14%' }} />
          <col style={{ width: '9%' }} />
          <col style={{ width: '9%' }} />
          <col style={{ width: '10%' }} />
          <col style={{ width: '9%' }} />
          <col style={{ width: '11%' }} />
          <col style={{ width: '7%' }} />
          <col style={{ width: '29%' }} />
        </colgroup>
        <thead>
          <tr>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Tax Declaration Number</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Declarant</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Lot Number</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Survey Number</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Area</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Title Number</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Assessed Value</th>
            <th style={{ border: '1px solid #ddd', padding: 1, fontSize: 9, backgroundColor: '#cccccc' }}>Effectivity</th>
            <th style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, backgroundColor: '#cccccc' }}>Memoranda</th>
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

            return (
              <tr key={index}>
                <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top', wordBreak: 'break-all', overflowWrap: 'anywhere' }}>
                  <div style={{
                    color: isConsolidated ? '#ed6c02' : 'inherit',
                    fontWeight: 600
                  }}>
                    {item.tax_declaration_number || ''}
                  </div>
                  {item.pin && (
                    <div style={{
                      fontSize: 8,
                      color: isConsolidated ? '#ed6c02' : 'inherit',
                      marginTop: 2
                    }}>
                      {'PIN: ' + item.pin}
                    </div>
                  )}
                </td>
                <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>
                  {(() => {
                    const d = normalizeDeclarantString(item.declarant_name);
                    const b = item.business_name
                      ? String(item.business_name).replace(/,\s*/g, ' ')
                      : '';
                    if (!d && !b) return '';
                    return (
                      <>
                        {d && (
                          <Typography variant="body2" component="span" sx={{ fontWeight: 'bold', fontSize: 10, display: 'block', lineHeight: 1.2 }}>
                            {d}
                          </Typography>
                        )}
                        {b && (
                          <Typography variant="caption" color="text.secondary" display="block" sx={{ fontSize: 9, lineHeight: 1.1 }}>
                            {b}
                          </Typography>
                        )}
                      </>
                    );
                  })()}
                </td>
                <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>{item.lot_number || ''}</td>
                <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>{item.survey_number || ''}</td>
                <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>
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
                    if (hasHa) {
                      const unit = numHa <= 1 ? 'ha' : 'has';
                      currentArea = `${numHa.toFixed(4)} ${unit}`;
                    } else if (hasSqm) {
                      currentArea = `${numSqm.toFixed(2)} sqm`;
                    }

                    if (hasOldHa && currentArea) {
                      return `${currentArea} (Old: ${oldHaRaw})`;
                    } else if (hasOldHa) {
                      return oldHaRaw;
                    } else {
                      return currentArea || '';
                    }
                  })()}
                </td>
                <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top', wordBreak: 'break-all', overflowWrap: 'anywhere', hyphens: 'none' }}>
                  {item.title_number || ''}
                </td>
                <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>
                  {(() => {
                    const currentValue = item.assessed_value;
                    const oldValue = item.assessed_value_old;
                    const hasCurrent = currentValue !== undefined && currentValue !== null;
                    const hasOld = oldValue && oldValue !== '';

                    if (!hasCurrent && !hasOld) return '₱0.00';

                    let displayValue = '';
                    if (hasCurrent) {
                      displayValue = `₱${Number(currentValue).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                    }

                    if (hasOld && displayValue) {
                      return `${displayValue} ${oldValue}`;
                    } else if (hasOld) {
                      return oldValue;
                    } else {
                      return displayValue || '₱0.00';
                    }
                  })()}
                </td>
                <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top' }}>{item.effectivity_date || ''}</td>
                <td style={{ border: '1px solid #ddd', padding: 4, fontSize: 10, verticalAlign: 'top', textAlign: 'left' }}>
                  <div style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', overflowWrap: 'anywhere' }}>{item.memoranda || ''}</div>
                </td>
              </tr>
            );
          })}
        </tbody>
        <tfoot>
          <tr>
            <td colSpan="8" style={{ height: 0, lineHeight: 0, padding: 0, borderTop: '1px solid #ddd' }} />
          </tr>
        </tfoot>
      </table>

      {/* Certification, Signatories & Receipt Docket section (Aistudio translation) */}
      <div className="print-signature print-certification-section" style={{ width: '100%', paddingLeft: '8mm', paddingRight: '8mm' }}>

        {/* Given Statement */}
        {/* <p className="print-given-statement">
          Given this <strong>{dayWithSuffix}</strong> day of <strong>{month}</strong>, <strong>{year}</strong> at the Office of the Municipal Assessor, <strong>{placeIssued || 'Kitaotao, Bukidnon'}</strong>.
        </p> */}

        {/* 3 Official Signatories (Aistudio grid layout) */}
        <div className="print-signatories">
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
          <div className="print-receipt-docket">
            <div className="print-receipt-docket-header">
              <div className="print-receipt-docket-title">
                <Receipt className="print-receipt-icon" />
                <span>OFFICIAL RECEIPT PARTICULARS</span>
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
          This document is generated by the Assessor's Archive System. Any alteration or erasure invalidates this certificate.
        </p>

      </div>
    </div>
  );
});

HistoryPrintDocument.displayName = 'HistoryPrintDocument';

export const HISTORY_PRINT_PAGE_STYLE = `
  @page {
    size: A4 portrait;
    margin: 12mm 8mm 16mm 8mm;

    @bottom-right {
      content: counter(page) "/" counter(pages);
      font-family: Arial, sans-serif;
      font-size: 10px;
      color: #666;
    }
  }
`;

export default HistoryPrintDocument;
