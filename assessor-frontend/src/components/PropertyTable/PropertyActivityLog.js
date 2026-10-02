import React, { useState, useEffect, useCallback } from 'react';
import {
  RotateCcw,
  Activity,
  CheckCircle2,
  GitBranch,
  Trash2
} from 'lucide-react';
import { apiService } from '../../utils/api';
import { formatAppDateTime } from '../../utils/dateTime';

export const AUDIT_FIELD_LABELS = {
  tax_declaration_number: 'Tax Declaration Number',
  property_state: 'Property State',
  pin: 'PIN',
  arp_no: 'ARP Number',
  declarant_name: 'Declarant Name',
  declarant_last_name: 'Last Name',
  declarant_first_name: 'First Name',
  declarant_middle_initial: 'Middle Initial',
  business_name: 'Business Name',
  owner_address: 'Address',
  administrator_name: 'Administrator',
  administrator_address: 'Admin Address',
  location: 'Location',
  barangay: 'Barangay',
  municipality: 'Municipality',
  province: 'Province',
  oct_tct_no: 'Title Number',
  title_number: 'Title Number',
  survey_no: 'Survey Number',
  survey_number: 'Survey Number',
  lot_no: 'Lot Number',
  lot_number: 'Lot Number',
  block_no: 'Block Number',
  block_number: 'Block Number',
  unique_lot_number_identified: 'Unique Lot Number',
  unique_lot_number: 'Unique Lot Number',
  gen_class: 'Classification',
  gen_class_name: 'Classification',
  actual_use: 'Actual Use',
  kind_of_property: 'Kind of Property',
  area_hectare: 'Area',
  area_sqm: 'Area (Sqm)',
  market_value: 'Market Value',
  assessment_level: 'Assessment Level (%)',
  assessed_value: 'Assessed Value',
  effectivity_date: 'Effectivity',
  effectivity_exempt: 'Effectivity Status',
  taxability: 'Taxability',
  previous_tax_declaration_number: 'Predecessor TDN',
  memoranda: 'Memoranda',
  appraised_by: 'Appraised By',
  tax_mapped_by: 'Tax Mapped By',
  municipal_assessor_name: 'Municipal Assessor'
};

export const IGNORED_AUDIT_FIELDS = new Set([
  'id',
  'created_at',
  'updated_at',
  'created_by',
  'updated_by'
]);

export const formatAuditFieldName = (fieldKey) => {
  if (AUDIT_FIELD_LABELS[fieldKey]) return AUDIT_FIELD_LABELS[fieldKey];
  return fieldKey
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
};

export const formatAuditFieldValue = (fieldKey, value) => {
  if (value === null || value === undefined || value === '') {
    return '—';
  }

  if (fieldKey === 'assessed_value' || fieldKey === 'market_value') {
    const num = Number(value);
    if (!isNaN(num)) {
      return `₱${num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
  }

  if (fieldKey === 'area_hectare') {
    const num = Number(value);
    if (!isNaN(num)) {
      return `${num.toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 })} ha`;
    }
  }

  if (fieldKey === 'area_sqm') {
    const num = Number(value);
    if (!isNaN(num)) {
      return `${num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} sqm`;
    }
  }

  if (fieldKey === 'effectivity_exempt') {
    return (value === 1 || value === '1' || value === true) ? 'EXEMPT' : 'TAXABLE / NORMAL';
  }

  if (typeof value === 'boolean') {
    return value ? 'Yes' : 'No';
  }

  if (typeof value === 'object') {
    try {
      return JSON.stringify(value);
    } catch (_) {
      return String(value);
    }
  }

  return String(value);
};

export const parseAuditChanges = (log) => {
  try {
    if (log && log.changes) {
      const parsed = typeof log.changes === 'string' ? JSON.parse(log.changes) : log.changes;
      if (parsed && typeof parsed === 'object') {
        const sample = Object.values(parsed)[0];
        if (sample && (Object.prototype.hasOwnProperty.call(sample, 'old_value') || Object.prototype.hasOwnProperty.call(sample, 'new_value'))) {
          return Object.entries(parsed)
            .filter(([k]) => !IGNORED_AUDIT_FIELDS.has(k))
            .filter(([, delta]) => {
              const oldV = delta?.old_value;
              const newV = delta?.new_value;
              return String(oldV ?? '') !== String(newV ?? '');
            })
            .map(([field, delta]) => ({
              field,
              oldValue: delta?.old_value,
              newValue: delta?.new_value
            }));
        }
      }
    }

    const oldVals = log && log.old_values ? (typeof log.old_values === 'string' ? JSON.parse(log.old_values) : log.old_values) : {};
    const newVals = log && log.new_values ? (typeof log.new_values === 'string' ? JSON.parse(log.new_values) : log.new_values) : {};
    const allKeys = Array.from(new Set([...Object.keys(oldVals || {}), ...Object.keys(newVals || {})]))
      .filter((k) => !IGNORED_AUDIT_FIELDS.has(k));

    return allKeys
      .filter((field) => {
        const oldV = oldVals ? oldVals[field] : undefined;
        const newV = newVals ? newVals[field] : undefined;
        return String(oldV ?? '') !== String(newV ?? '');
      })
      .map((field) => ({
        field,
        oldValue: oldVals ? oldVals[field] : undefined,
        newValue: newVals ? newVals[field] : undefined
      }));
  } catch (_) {
    return [];
  }
};

/**
 * Single activity event item (clean, quiet, professional record block)
 */
const ActivityEventItem = ({ log, property, compact, isLast }) => {
  const [expanded, setExpanded] = useState(false);

  const actionType = String(log.action || '').toLowerCase();
  const userName = log.user_name || 'System';
  const changes = parseAuditChanges(log);

  let title = 'Record Updated';
  let ActionIcon = Activity;
  let iconColor = 'text-slate-500';

  if (actionType === 'create' || actionType === 'insert') {
    title = 'Property Created';
    ActionIcon = CheckCircle2;
    iconColor = 'text-emerald-600';
  } else if (actionType === 'update') {
    title = 'Property Updated';
    ActionIcon = Activity;
    iconColor = 'text-slate-600';
  } else if (actionType === 'state_change') {
    title = 'Status Changed';
    ActionIcon = GitBranch;
    iconColor = 'text-amber-600';
  } else if (actionType === 'delete') {
    title = 'Property Deleted';
    ActionIcon = Trash2;
    iconColor = 'text-rose-600';
  }

  const changeCount = changes.length;
  let changeCountText = 'Record updated';
  if (changeCount === 1) {
    changeCountText = '1 change';
  } else if (changeCount > 1) {
    changeCountText = `${changeCount} changes`;
  }

  // Limit initially displayed changes if > 5
  const shouldLimit = changeCount > 5;
  const displayedChanges = (shouldLimit && !expanded) ? changes.slice(0, 5) : changes;

  // Find state change info
  const stateChangeItem = changes.find((c) => c.field === 'property_state');
  const oldState = stateChangeItem ? stateChangeItem.oldValue : 'CURRENT';
  const newState = stateChangeItem ? stateChangeItem.newValue : 'CANCELLED';

  return (
    <div className={`space-y-3 ${compact ? 'text-xs' : 'text-sm'}`}>
      {/* Event Header */}
      <div className="space-y-1">
        {/* Line 1: Title with small icon */}
        <div className="flex items-center gap-2">
          <ActionIcon className={`w-4 h-4 shrink-0 ${iconColor}`} />
          <span className="font-semibold text-slate-800">
            {title}
          </span>
        </div>

        {/* Line 2: User · Timestamp */}
        <div className="text-xs text-slate-500 pl-6">
          <span>{userName}</span>
          <span className="mx-1.5 text-slate-300">·</span>
          <span>{formatAppDateTime(log.created_at)}</span>
        </div>

        {/* Line 3: Change count (for updates or state changes) */}
        {actionType === 'update' && (
          <div className="text-xs text-slate-400 pl-6">
            {changeCountText}
          </div>
        )}
        {actionType === 'state_change' && (
          <div className="text-xs text-slate-400 pl-6">
            1 change
          </div>
        )}
      </div>

      {/* Event Details Content */}
      <div className="pl-6 space-y-2">
        {actionType === 'create' || actionType === 'insert' ? (
          <div className="space-y-2 text-xs">
            <div className="text-slate-600">
              Property record created.
            </div>
            {property?.tax_declaration_number && (
              <div className="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-6 pt-1 text-slate-700">
                <span className="text-slate-500 font-medium sm:w-44 shrink-0">Tax Declaration</span>
                <span className="font-mono font-medium text-slate-800">{property.tax_declaration_number}</span>
              </div>
            )}
          </div>
        ) : actionType === 'delete' ? (
          <div className="text-xs text-rose-700">
            This property record was deleted.
          </div>
        ) : actionType === 'state_change' ? (
          <div className="flex flex-col sm:flex-row sm:items-baseline gap-1 sm:gap-6 text-xs pt-1">
            <span className="text-slate-500 font-medium sm:w-44 shrink-0">Property State</span>
            <div className="flex items-center gap-2 font-mono">
              <span className="text-slate-500 font-normal">{oldState || '—'}</span>
              <span className="text-slate-400">→</span>
              <span className="font-bold text-slate-900">{newState || '—'}</span>
            </div>
          </div>
        ) : changeCount > 0 ? (
          <div className="space-y-2 pt-1">
            {/* Clean row-based changes without table borders */}
            <div className="space-y-1.5">
              {displayedChanges.map((ch, chIdx) => (
                <div
                  key={chIdx}
                  className="flex flex-col sm:flex-row sm:items-baseline gap-0.5 sm:gap-6 text-xs py-0.5"
                >
                  {/* Field Label */}
                  <span className="text-slate-600 font-medium sm:w-44 shrink-0">
                    {formatAuditFieldName(ch.field)}
                  </span>

                  {/* Before → After */}
                  <div className="flex items-baseline gap-2 font-mono flex-1 min-w-0">
                    <span className="text-slate-400 break-words font-normal">
                      {formatAuditFieldValue(ch.field, ch.oldValue)}
                    </span>
                    <span className="text-slate-400 shrink-0 select-none">→</span>
                    <span className="text-slate-800 font-semibold break-words">
                      {formatAuditFieldValue(ch.field, ch.newValue)}
                    </span>
                  </div>
                </div>
              ))}
            </div>

            {/* Show all / Hide additional changes */}
            {shouldLimit && (
              <div className="pt-1 text-xs">
                <button
                  type="button"
                  onClick={() => setExpanded(!expanded)}
                  className="text-sky-700 hover:text-sky-900 font-medium hover:underline cursor-pointer"
                >
                  {expanded ? 'Hide additional changes' : `Show all ${changeCount} changes`}
                </button>
              </div>
            )}
          </div>
        ) : (
          <div className="text-xs text-slate-400 italic">
            Record updated with no specific property field deltas captured.
          </div>
        )}
      </div>

      {/* Subtle Divider between events */}
      {!isLast && (
        <div className="pt-3">
          <hr className="border-t border-slate-100" />
        </div>
      )}
    </div>
  );
};

/**
 * Reusable Property Activity Log component.
 * Used in both PropertyDossierModal (full mode) and regular Tax Declaration History (compact mode).
 */
const PropertyActivityLog = ({
  property,
  compact = false,
  onActivityLoaded
}) => {
  const [logs, setLogs] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(false);

  const propertyId = property?.id;

  const fetchActivity = useCallback(async () => {
    if (!propertyId) {
      setLogs([]);
      setLoading(false);
      setError(false);
      if (onActivityLoaded) onActivityLoaded([]);
      return;
    }

    try {
      setLoading(true);
      setError(false);
      const res = await apiService.getAuditTrail({
        page: 1,
        per_page: 100,
        table: 'assessor_properties',
        record_id: propertyId,
        _t: Date.now()
      });

      const auditList = (res && Array.isArray(res.data)) ? res.data : (Array.isArray(res) ? res : []);
      setLogs(auditList);
      if (onActivityLoaded) onActivityLoaded(auditList);
    } catch (err) {
      console.error('Failed to load property activity log:', err);
      setLogs([]);
      setError(true);
      if (onActivityLoaded) onActivityLoaded([]);
    } finally {
      setLoading(false);
    }
  }, [propertyId, onActivityLoaded]);

  useEffect(() => {
    fetchActivity();
  }, [fetchActivity]);

  if (!propertyId) {
    return (
      <div className={`text-center text-slate-500 bg-slate-50 rounded-lg border border-slate-100 ${compact ? 'p-4 text-xs' : 'p-6 text-sm'}`}>
        Activity unavailable for this record.
      </div>
    );
  }

  if (loading) {
    return (
      <div className={`flex items-center justify-center gap-2 text-slate-500 text-xs ${compact ? 'py-6' : 'py-10'}`}>
        <span className="inline-block w-4 h-4 border-2 border-slate-400 border-t-transparent rounded-full animate-spin" />
        <span>Loading activity…</span>
      </div>
    );
  }

  if (error) {
    return (
      <div className={`text-center space-y-2 text-xs ${compact ? 'py-4' : 'py-6'}`}>
        <p className="text-slate-600 font-medium">Unable to load property activity.</p>
        <button
          type="button"
          onClick={fetchActivity}
          className="inline-flex items-center gap-1.5 px-3 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded transition-colors cursor-pointer"
        >
          <RotateCcw className="w-3 h-3" />
          <span>Retry</span>
        </button>
      </div>
    );
  }

  if (!logs || logs.length === 0) {
    return (
      <div className={`text-center space-y-1 text-slate-500 ${compact ? 'py-6' : 'py-10'}`}>
        <div className="font-semibold text-slate-700 text-xs sm:text-sm">No activity yet</div>
        <div className="text-xs text-slate-400">
          Changes made to this tax declaration will appear here.
        </div>
      </div>
    );
  }

  const eventCountText = logs.length === 1 ? '1 event' : `${logs.length} events`;

  return (
    <div className={`space-y-4 ${compact ? 'p-1' : 'p-2'}`}>
      {/* Quiet Top Header */}
      <div className="flex items-center justify-between pb-2 border-b border-slate-100">
        <div>
          <div className="text-xs font-bold text-slate-700 uppercase tracking-wider">
            Property Activity
          </div>
          <div className="text-[11px] text-slate-400">
            {eventCountText}
          </div>
        </div>

        <button
          type="button"
          onClick={fetchActivity}
          title="Refresh activity"
          className="p-1 text-slate-400 hover:text-slate-700 rounded transition-colors cursor-pointer"
        >
          <RotateCcw className="w-3.5 h-3.5" />
        </button>
      </div>

      {/* Events List */}
      <div className={compact ? 'space-y-4 pt-1' : 'space-y-6 pt-2'}>
        {logs.map((log, idx) => (
          <ActivityEventItem
            key={log.id || `activity-${idx}`}
            log={log}
            property={property}
            compact={compact}
            isLast={idx === logs.length - 1}
          />
        ))}
      </div>
    </div>
  );
};

export default PropertyActivityLog;
