import React, { forwardRef, useState, useEffect } from 'react';
import ReactDOMServer from 'react-dom/server';
import { Typography } from '@mui/material';
import { Receipt, Layers, AlignJustify, QrCode, Scan, Copy, Check, X } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { formatAppDate, formatAppDateTime } from '../../utils/dateTime';

import {
  HISTORY_TABLE_STYLES,
  DEFAULT_HISTORY_TABLE_STYLE
} from './HistoryTableStyles';
import './HistoryPrintDocument.css';

/**
 * Dynamically calculates the logo slot size in QRCodeSVG viewBox units (modules)
 * based on the actual QR matrix dimension.
 *
 * Formula:
 * const logoSlotUnits = Math.min(viewBoxSize * 0.25, 9);
 */
export const calculateQrLogoSlot = (value, level = 'H') => {
  if (!value) return 0;
  try {
    const tempSvg = ReactDOMServer.renderToStaticMarkup(
      React.createElement(QRCodeSVG, {
        value,
        level,
        size: 100,
        includeMargin: false,
      })
    );
    const match = tempSvg.match(/viewBox="0 0 (\d+) (\d+)"/);
    const viewBoxSize = match && match[1] ? parseInt(match[1], 10) : 21;
    const logoSlotUnits = Math.min(viewBoxSize * 0.25, 15);
    return Math.round(logoSlotUnits * 100) / 100;
  } catch {
    return 5;
  }
};

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
const HistoryPrintDocument = forwardRef(
  ({
    settings,
    printHistory,
    requestData,
    printPropertyData,
    documentType,
    paperSize = 'a4',
    printGeneratedAt,
    historyTableStyle = DEFAULT_HISTORY_TABLE_STYLE
  }, ref) => {
    const isRequest = documentType === 'request' || (documentType === undefined && Boolean(requestData && (requestData.id || requestData.receipt_number || requestData.purpose || requestData.purpose_details || requestData.client_name)));

    const activeHistoryTableStyle =
      HISTORY_TABLE_STYLES[historyTableStyle] ||
      HISTORY_TABLE_STYLES[DEFAULT_HISTORY_TABLE_STYLE];

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
      ? (isRequest ? formatDateOnly(rawDateIssued) : formatAppDateTime(rawDateIssued))
      : '—';
    const formattedGeneratedAt = printGeneratedAt
      ? formatAppDateTime(printGeneratedAt, {
        month: 'long',
        second: '2-digit',
      })
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

    // Lot Number Resolution
    const resolvedLotNumber =
      printHistory?.[0]?.lot_number ||
      printPropertyData?.lot_number ||
      requestData?.lot_number ||
      requestData?.lotNumber ||
      'N/A';

    // Additional QR Payload fields resolution from current document data
    const qrTdNumber =
      (printHistory && printHistory[0] && printHistory[0].tax_declaration_number) ||
      printPropertyData?.tax_declaration_number ||
      requestData?.tax_declaration_number ||
      '';

    const qrControlNo =
      (requestData && (requestData.cert_number || requestData.control_no || requestData.control_number)) ||
      '';

    const qrPin =
      (printHistory && printHistory[0] && printHistory[0].pin) ||
      printPropertyData?.pin ||
      requestData?.pin ||
      '';

    const qrOwner =
      normalizeDeclarantString(printHistory?.[0]?.declarant_name) ||
      (printPropertyData && normalizeDeclarantString(printPropertyData.declarant_name)) ||
      (requestData && (requestData.owner_name || requestData.client_name)) ||
      '';

    const qrOrNumber = receiptNumber || (requestData && requestData.receipt_number) || '';

    const qrDateIssued = formattedDateIssued !== '—' ? formattedDateIssued : (rawDateIssued || '');

    const qrMunicipality = `${toFormalCase(baseMunicipality)}, ${toFormalCase(baseProvince)}`;

    const qrTimeGenerated = formattedGeneratedAt !== '—'
      ? formattedGeneratedAt
      : (printGeneratedAt ? formatAppDateTime(printGeneratedAt) : formatAppDateTime(new Date()));

    // Distinct payloads based on document type (PropertyTable vs RequestTable)
    // For RequestTable: concise labels reduce QR density while keeping full verification information
    const qrPayload = isRequest
      ? [
        `LOT NUMBER: ${resolvedLotNumber}`,
        qrTdNumber ? `TD NUMBER: ${qrTdNumber}` : null,
        qrPin ? `PIN: ${qrPin}` : null,
        qrOrNumber ? `RECEIPT NO: ${qrOrNumber}` : null,
        amountPaidFormatted && amountPaidFormatted !== '0.00' ? `AMOUNT PAID: ${currencySymbol}${amountPaidFormatted}` : null,
        qrDateIssued ? `DATE ISSUED: ${qrDateIssued}` : null,
        qrMunicipality ? `MUNICIPALITY: ${qrMunicipality}` : null,
        `STATUS: OFFICIAL RECORD`,
        `REF ID: ${referenceId}`
      ].filter(Boolean).join('\n')
      : [
        `LOT NUMBER: ${resolvedLotNumber}`,
        qrTdNumber ? `TD NUMBER: ${qrTdNumber}` : null,
        qrPin ? `PIN: ${qrPin}` : null,
        // qrOwner ? `DECLARED OWNER: ${qrOwner}` : null,
        `TIME GENERATED: ${qrTimeGenerated}`,
        qrMunicipality ? `MUNICIPALITY: ${qrMunicipality}` : null,
        `STATUS: ARCHIVE RECORD VERIFICATION`,
        `REF ID: ${referenceId}`
      ].filter(Boolean).join('\n');

    // QR Scan Modal State
    const [isQrModalOpen, setIsQrModalOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const [paddedLogoUrl, setPaddedLogoUrl] = useState(appLogoUrl);

    useEffect(() => {
      if (!appLogoUrl) {
        setPaddedLogoUrl('');
        return;
      }
      if (typeof window === 'undefined' || typeof document === 'undefined') {
        setPaddedLogoUrl(appLogoUrl);
        return;
      }

      let isMounted = true;
      const img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = () => {
        try {
          const canvas = document.createElement('canvas');
          // High-resolution canvas (256x256) to ensure razor-sharp rendering on print and in modal preview
          const size = 256;
          canvas.width = size;
          canvas.height = size;
          const ctx = canvas.getContext('2d');
          if (ctx) {
            // Fill entire slot background with pure white
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, size, size);

            // Inset logo by exactly 10% on each side to create subtle whitespace inside the slot
            const pad = Math.round(size * 0.10);
            const drawSize = size - pad * 2;
            ctx.drawImage(img, pad, pad, drawSize, drawSize);

            const dataUrl = canvas.toDataURL('image/png');
            if (isMounted) {
              setPaddedLogoUrl(dataUrl);
            }
            return;
          }
        } catch (e) {
          // Fallback if canvas is tainted by CORS
        }
        if (isMounted) {
          setPaddedLogoUrl(appLogoUrl);
        }
      };
      img.onerror = () => {
        if (isMounted) {
          setPaddedLogoUrl(appLogoUrl);
        }
      };
      img.src = appLogoUrl;

      return () => {
        isMounted = false;
      };
    }, [appLogoUrl]);

    const handleOpenScanModal = () => {
      setCopied(false);
      setIsQrModalOpen(true);
    };

    const handleCloseScanModal = () => {
      setIsQrModalOpen(false);
    };

    const handleCopyQrPayload = () => {
      if (!qrPayload) return;
      if (navigator?.clipboard?.writeText) {
        navigator.clipboard.writeText(qrPayload)
          .then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
          })
          .catch(() => {
            // Graceful fallback
            setCopied(false);
          });
      }
    };

    // Shared QR Base component following PropertyTable's design exactly:
    // Outer box: 72px x 72px, 1px solid #94a3b8 border, 4px radius, 2px padding, white background.
    // QR SVG: 68px x 68px with level H and excavate: true using built-in imageSettings with canonical logo slot.
    const renderQrBaseCard = (extraClass = '', qrSize = 68, caption = 'Scan to Verify') => {
      // Calculate logo slot size in QRCodeSVG viewBox coordinate units:
      // logoSlotUnits = Math.min(viewBoxSize * 0.25, 9)
      const slotUnits = calculateQrLogoSlot(qrPayload, 'H');

      const imageSettings = paddedLogoUrl && slotUnits > 0
        ? {
          src: paddedLogoUrl,
          width: slotUnits,
          height: slotUnits,
          excavate: true
        }
        : undefined;

      return (
        <div className={`print-property-archive-right print-request-qr-side-inner ${extraClass}`}>
          <div
            className="qr-flush-container property-qr-box receipt-property-qr-box"
            onClick={handleOpenScanModal}
            title="Official QR Verification Base - Click to preview scanned data"
            role="button"
            tabIndex={0}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                handleOpenScanModal();
              }
            }}
          >
            <QRCodeSVG
              value={qrPayload}
              size={qrSize}
              width={qrSize}
              height={qrSize}
              level="H"
              includeMargin={false}
              imageSettings={imageSettings}
              className="qr-flush-svg"
            />
          </div>
          {caption && (
            <span className="print-property-qr-caption">
              {caption}
            </span>
          )}
        </div>
      );
    };

    // Shared QR Verification Strip button
    const renderVerificationStrip = () => (
      <button
        type="button"
        onClick={handleOpenScanModal}
        className="qr-verification-strip-btn"
        title="Click to preview QR code scan result"
      >
        <QrCode className="qr-strip-icon" />
        <span>
          {/* QR Base: <strong className="qr-strip-lot">Lot {resolvedLotNumber}</strong> &bull; */}Ref ID:{' '}
          <strong className="qr-strip-ref">{referenceId}</strong>
        </span>
      </button>
    );

    // Signatory 1: Prepared by resolution
    const preparedByName = (requestData && requestData.prepared_by)
      || (printHistory && printHistory[0] && printHistory[0].updated_by_name)
      || '';
    const preparedByTitle = (requestData && requestData.prepared_by_title)
      || (settings && settings.prepared_by_title)
      || 'ADMINISTRATIVE AIDE';

    const propertySignatorySource =
      !isRequest
        ? (printPropertyData || printHistory?.[0] || null)
        : null;

    // Signatory 2: Verifier signatory resolution:
    const verifierSignatoryFullName =
      (isRequest && requestData?.verifier_signatory_name) ||
      propertySignatorySource?.verifier_signatory_name ||
      printHistory?.[0]?.verifier_signatory_name ||
      settings?.verifier_signatory_name ||
      '';

    const verifierSignatoryTitle =
      (isRequest && requestData?.verifier_signatory_title) ||
      propertySignatorySource?.verifier_signatory_title ||
      printHistory?.[0]?.verifier_signatory_title ||
      settings?.verifier_signatory_title ||
      'Local Assessment Operations Officer II / Appraiser';

    // Signatory 3: Municipal assessor signatory resolution:
    const approvalLabel = (requestData && requestData.approval_label)
      || (settings && settings.approval_label)
      || 'Certified correct as to available record/s:';

    const assessorName =
      (isRequest && requestData?.municipal_assessor_name) ||
      propertySignatorySource?.municipal_assessor_name ||
      printHistory?.[0]?.municipal_assessor_name ||
      settings?.municipal_assessor_name ||
      '';

    const assessorSuffix =
      (isRequest && requestData?.municipal_assessor_suffix) ||
      propertySignatorySource?.municipal_assessor_suffix ||
      printHistory?.[0]?.municipal_assessor_suffix ||
      settings?.municipal_assessor_suffix ||
      '';

    const assessorTitle =
      (isRequest && requestData?.municipal_assessor_title) ||
      propertySignatorySource?.municipal_assessor_title ||
      printHistory?.[0]?.municipal_assessor_title ||
      settings?.municipal_assessor_title ||
      'MUNICIPAL ASSESSOR';

    const assessorLicense =
      (isRequest && requestData?.municipal_assessor_license) ||
      propertySignatorySource?.municipal_assessor_license ||
      printHistory?.[0]?.municipal_assessor_license ||
      settings?.municipal_assessor_license ||
      '';



    const paperClass = `paper-${paperSize || 'a4'}`;

    return (
      <div
        ref={ref}
        className={`print-root document-page ${paperClass}`}
        style={{
          '--history-header-bg': activeHistoryTableStyle.header,
          '--history-row-odd': activeHistoryTableStyle.odd,
          '--history-row-even': activeHistoryTableStyle.even,
          '--history-row-hover': activeHistoryTableStyle.hover,

          '--history-print-header-bg':
            activeHistoryTableStyle.printHeader || activeHistoryTableStyle.header,

          '--history-print-row-odd':
            activeHistoryTableStyle.printOdd || activeHistoryTableStyle.odd,

          '--history-print-row-even':
            activeHistoryTableStyle.printEven || activeHistoryTableStyle.even,
        }}
      >
        {/* Subtle watermark circle */}
        {/* <div className="print-watermark" aria-hidden="true">
        <div className="print-watermark-inner">
          OFFICE OF THE MUNICIPAL ASSESSOR
        </div>
      </div> */}

        <div className="print-header">
          {/* <div className="print-header-control-no">
          <span className="print-header-control-label">CONTROL NO.</span>
          <span className="print-header-control-val">{referenceId}</span>
        </div> */}
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
            <col style={{ width: '10%' }} />
            <col style={{ width: '6%' }} />
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

              const rowBackground =
                index % 2 === 0
                  ? activeHistoryTableStyle.odd
                  : activeHistoryTableStyle.even;

              return (
                <tr
                  key={index}
                  className={isActiveRow ? 'active-row' : undefined}
                  style={{
                    backgroundColor: rowBackground
                  }}
                >
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
          <tfoot>
            <tr>
            </tr>
          </tfoot>
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

          {/* {!isRequest && (
            <div className="print-receipt-footer2">
              <span>System Generated Copy • {formattedUpdatedAt}</span>
              <span>Ref ID: {referenceId}</span>
            </div>
          )} */}
          {/* Official Receipt Particulars / Local Government Audit Trail Box (Request Only) */}
          {isRequest && (
            <>
              <div className="print-receipt-docket receipt-box">
                <div className="print-receipt-docket-header">
                  <div className="print-receipt-docket-title">
                    <Receipt className="print-receipt-icon" />
                    <span className="print-receipt-title-main">OFFICIAL RECEIPT PARTICULARS</span>
                  </div>
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
                    <div className="print-receipt-purpose-content">
                      <span className="print-receipt-field-label">Requested by:</span>
                      <span className="print-receipt-val-purpose">{clientName}</span>
                      <span className="print-receipt-field-label">Purpose:</span>
                      <span className="print-receipt-val-purpose">{purpose}</span>
                    </div>

                    <div className="print-receipt-purpose-qr">
                      {renderQrBaseCard('request-receipt-qr', 68, '')}
                    </div>
                  </div>
                </div>

                <div className="print-receipt-footer">
                  <span className="print-receipt-seal">VALID ONLY WITH OFFICIAL RAISED DRY SEAL</span>
                  {/* <span>Ref ID: {referenceId}</span> */}
                  <div className="print-verification-strip-container">
                    {renderVerificationStrip()}
                  </div>
                </div>
              </div>

              {/* Clickable QR verification strip beneath the receipt box */}
            </>
          )}

          {/* Property-only bottom document-verification archive panel (Non-Certificate) */}
          {!isRequest && (
            <div className="print-property-archive-panel">
              <div className="print-property-archive-left">
                <div className="print-property-archive-header">
                  <span className="print-property-badge">
                    RECORD VERIFICATION DATA
                  </span>
                </div>

                <p className="print-property-archive-desc">
                  Verified Real Property Archive Data for <strong className="print-property-owner-strong">{qrOwner || 'RECORD HOLDER'}</strong>. This record is synchronized with the assessor archiving system of <strong>{qrMunicipality}</strong>.
                </p>

                <div className="print-property-archive-meta">
                  <span>LOT NO: <strong className="meta-strong">{resolvedLotNumber}</strong></span>
                  <span className="meta-sep">&bull;</span>
                  <span>TD NO: <strong className="meta-strong">{qrTdNumber || '—'}</strong></span>
                  <span className="meta-sep">&bull;</span>
                  <span>PIN: <strong className="meta-strong">{qrPin || '—'}</strong></span>
                  <span className="meta-sep">&bull;</span>
                  <span>TIME GENERATED: <strong className="meta-strong">{qrTimeGenerated}</strong></span>
                  <span className="meta-sep">&bull;</span>
                  <span className="meta-status">STATUS: ARCHIVE VERIFIED</span>
                  <span className="meta-sep">&bull;</span>
                  {isRequest ? '' : (
                    <span>
                      REF ID: <strong className="meta-strong">{referenceId || '—'}</strong>
                    </span>
                  )}
                </div>
              </div>

              {/* Right: Square QR Code with Scan to Verify caption */}
              {renderQrBaseCard('print-property-archive-qr', 68, 'Scan to Verify')}
            </div>
          )}

          {/* Bottom security notice */}
          <p className="print-security-notice">
            This document is generated by the Assessor's Archive System. Any alteration or erasure invalidates this document.
          </p>
          {/* <div className="print-receipt-footer">
            <span>
              {isRequest
                ? `System Generated Official Copy • ${formattedGeneratedAt}`
                : `System Generated Copy • ${formattedGeneratedAt}`}
            </span>
            <span>
              {isRequest ? '' : `Ref ID: ${referenceId}`}
            </span>
          </div> */}
        </div>

        {/* Official QR Verification Base Modal (Screen Only) */}
        {isQrModalOpen && (
          <div
            className="qr-verification-modal-backdrop"
            onClick={handleCloseScanModal}
            role="presentation"
          >
            <div
              className="qr-verification-modal-dialog"
              onClick={(e) => e.stopPropagation()}
              role="dialog"
              aria-modal="true"
              aria-labelledby="qr-modal-title"
            >
              {/* Modal Header */}
              <div className="qr-modal-header">
                <div className="qr-modal-header-left">
                  <div className="qr-modal-header-icon-box">
                    <QrCode className="qr-modal-header-icon" />
                  </div>
                  <div>
                    <h3 id="qr-modal-title" className="qr-modal-title">Official QR Verification Base</h3>
                    <p className="qr-modal-subtitle">Decoded mobile scanner output preview</p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={handleCloseScanModal}
                  className="qr-modal-close-btn"
                  aria-label="Close modal"
                >
                  <X className="qr-modal-close-icon" />
                </button>
              </div>

              {/* Modal Body */}
              <div className="qr-modal-body">
                {/* Top summary highlight cards: Lot Number and Ref ID */}
                <div className="qr-modal-summary-grid">
                  <div className="qr-modal-summary-card lot-card">
                    <span className="qr-modal-summary-label lot-label">Lot Number</span>
                    <p className="qr-modal-summary-val lot-val" title={resolvedLotNumber}>
                      {resolvedLotNumber}
                    </p>
                    <span className="qr-modal-summary-caption lot-caption">Scanned Primary Field</span>
                  </div>

                  <div className="qr-modal-summary-card ref-card">
                    <span className="qr-modal-summary-label ref-label">Ref ID</span>
                    <p className="qr-modal-summary-val ref-val" title={referenceId}>
                      {referenceId}
                    </p>
                    <span className="qr-modal-summary-caption ref-caption">Unique Verification Ref</span>
                  </div>
                </div>

                {/* QR Code and Scanner Simulation */}
                <div className="qr-modal-scanner-section">
                  <div className="qr-modal-qr-preview-box">
                    <div className="qr-modal-preview-wrapper" style={{ position: 'relative', width: '180px', height: '180px', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                      <QRCodeSVG
                        value={qrPayload}
                        size={68}
                        level="H"
                        includeMargin={false}
                        imageSettings={paddedLogoUrl ? {
                          src: paddedLogoUrl,
                          width: calculateQrLogoSlot(qrPayload, 'H'),
                          height: calculateQrLogoSlot(qrPayload, 'H'),
                          excavate: true
                        } : undefined}
                        className="qr-modal-svg"
                      />
                    </div>
                    <span className="qr-modal-scan-hint">Scan With Camera</span>
                  </div>

                  <div className="qr-modal-payload-box">
                    <div className="qr-modal-payload-header">
                      <span className="qr-modal-payload-label">
                        <Scan className="qr-modal-scan-icon" />
                        <span>Decoded Text on Device</span>
                      </span>
                      <button
                        type="button"
                        onClick={handleCopyQrPayload}
                        className="qr-modal-copy-btn"
                      >
                        {copied ? <Check className="qr-copy-check-icon" /> : <Copy className="qr-copy-icon" />}
                        <span>{copied ? 'Copied' : 'Copy'}</span>
                      </button>
                    </div>

                    <div className="qr-modal-payload-code">
                      <pre>{qrPayload}</pre>
                    </div>
                  </div>
                </div>

                <p className="qr-modal-description">
                  Scanning this QR Code with an iPhone/Android camera, tablet, or handheld barcode scanner decodes the Lot Number, Reference ID, and official tax declaration records for immediate tamper-proof verification.
                </p>
              </div>

              {/* Modal Footer */}
              <div className="qr-modal-footer">
                <span className="qr-modal-control-info">
                  {qrControlNo ? `Control: ${qrControlNo}` : `Ref ID: ${referenceId}`}
                </span>
                <button
                  type="button"
                  onClick={handleCloseScanModal}
                  className="qr-modal-footer-close-btn"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        )}
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
      margin: 8mm 6mm 15mm;

      @bottom-right {
        content: counter(page) "/" counter(pages);
        font-family: 'Arial', sans-serif;
        font-size: 10px;
        color: #64748b;
      }
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
    .history-title,
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
    .history-memoranda,
    .memoranda-text,
    .badge-status {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
    }

    .history-table tbody td:nth-child(9) {
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

    .history-table th,
    .history-table td {
      overflow: visible !important;
    }

    .history-table {
      border-collapse: collapse !important;
      border-spacing: 0 !important;
      border: 1px solid #ddddddff !important;
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
      border: 1px solid #ddddddff !important;
      overflow: visible !important;
      word-break: break-word !important;
      overflow-wrap: break-word !important;
    }

    .history-table tbody td {
      padding: 6px 4px !important;
      border-right: 1px solid #ddddddff !important;
      border-bottom: 1px solid #ddddddff !important;
      overflow: visible !important;
    }

    .history-table-header-row th {
      border-bottom: 1px solid #ddddddff !important;
    }

    .history-memoranda,
    .memoranda-text {
      break-inside: auto !important;
      page-break-inside: auto !important;
      overflow: visible !important;
    }

    .info-card {
      border: 1px solid #ddddddff !important;
    }

    .info-row {
      border-bottom: 1px solid #ddddddff !important;
    }
    .info-row:last-child,
    .info-row-last {
      border-bottom: 0px !important;
    }

    .info-cell.border-right {
      border-right: 1px solid #ddddddff !important;
    }

    .print-signature,
    .signatories-grid,
    .print-receipt-docket,
    .receipt-box,
    .print-property-archive-panel,
    .print-property-verification-area,
    .qr-flush-container {
      page-break-inside: avoid !important;
      break-inside: avoid !important;
    }

    .qr-verification-modal-backdrop,
    .qr-verification-modal-dialog {
      display: none !important;
    }

    .qr-flush-container {
      background-color: #ffffff !important;
      border: none !important;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
      cursor: default !important;
    }

    .print-root .property-qr-box,
    .property-qr-box {
      border: 1px solid #94a3b8 !important;
      padding: 2px !important;
      background-color: #ffffff !important;
      border-radius: 4px !important;
      box-sizing: border-box !important;
      box-shadow: none !important;
    }

    .qr-flush-svg {
      display: block !important;
    }

    .qr-verification-strip-btn {
      cursor: default !important;
    }
  `;
};

// Backwards-compatible default style (A4)
export const HISTORY_PRINT_PAGE_STYLE = getHistoryPrintPageStyle('a4');

export default HistoryPrintDocument;

