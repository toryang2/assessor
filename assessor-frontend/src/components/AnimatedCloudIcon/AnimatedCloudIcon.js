import React from 'react';
import { Box } from '@mui/material';
import { motion } from 'framer-motion';

const AnimatedCloudIcon = ({ 
  status = 'idle', // 'idle' | 'syncing' | 'success' | 'failed' | 'incomplete'
  size = 24, 
  sx = {} 
}) => {
  const getStatusColor = () => {
    switch (status) {
      case 'syncing': return 'primary.main';
      case 'success': return 'success.main';
      case 'failed': return 'error.main';
      case 'incomplete': return 'warning.main';
      default: return 'inherit';
    }
  };

  const getInnerPath = () => {
    switch (status) {
      case 'syncing':
      case 'idle':
        // Up arrow inside the cloud
        return "M12 16v-6M9 13l3-3 3 3";
      case 'success':
        // Checkmark
        return "M9 13l2 2 4-4";
      case 'failed':
        // X cross
        return "M10 10l4 4m0-4-4 4";
      case 'incomplete':
        // Exclamation mark
        return "M12 10v4m0 3v.01";
      default:
        return "";
    }
  };

  // The outer cloud shape (3-bump classic style)
  const cloudPath = "M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75z";
  const innerPath = getInnerPath();
  const isAnimating = status === 'syncing';

  return (
    <Box sx={{ 
      width: size, 
      height: size, 
      display: 'flex', 
      alignItems: 'center', 
      justifyContent: 'center', 
      color: getStatusColor(), 
      opacity: status === 'idle' ? 0.6 : 1,
      ...sx 
    }}>
      <svg 
        width={size} 
        height={size} 
        viewBox="0 0 24 24" 
        fill="none" 
        stroke="currentColor" 
        strokeWidth="2.5" 
        strokeLinecap="round" 
        strokeLinejoin="round"
      >
        {isAnimating ? (
          <>
            <motion.path
              d={cloudPath}
              initial={{ pathLength: 0, opacity: 0.2 }}
              animate={{ pathLength: 1, opacity: 1 }}
              transition={{ 
                pathLength: { repeat: Infinity, duration: 2, ease: "linear" },
                opacity: { repeat: Infinity, duration: 2, ease: "linear" }
              }}
            />
            <motion.path
              d={innerPath}
              initial={{ pathLength: 0, opacity: 0.2 }}
              animate={{ pathLength: 1, opacity: 1 }}
              transition={{ 
                pathLength: { repeat: Infinity, duration: 1.5, ease: "linear", delay: 0.5 },
                opacity: { repeat: Infinity, duration: 1.5, ease: "linear", delay: 0.5 }
              }}
            />
          </>
        ) : (
          <>
            <path d={cloudPath} />
            {innerPath && <path d={innerPath} />}
          </>
        )}
      </svg>
    </Box>
  );
};

export default AnimatedCloudIcon;
